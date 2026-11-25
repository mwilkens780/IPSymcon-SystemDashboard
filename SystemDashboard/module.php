<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileKit.php';

/**
 * Modernes Dashboard fuer die Systemsteuerung. Buendelt eine frei
 * konfigurierbare, manuell sortierbare Liste von Variablen/Instanzen --
 * insbesondere HomeMatic-Systemvariablen, Device-Monitor-Status, FritzBox-
 * Objekte und eine Netzwerkgeraete-Uebersicht (bestehende HTML-Variable,
 * die bereits nur aktive Geraete listet) -- als einheitliche, moderne
 * Kachel-Grafik, damit nicht mehr ueber mehrere Menuepunkte verteilt.
 *
 * Bewusst keine eigene System-/Netzwerklogik -- reine Anzeige/Schalt-
 * Huelle um bereits vorhandene Variablen, identische Architektur zum
 * Home Dashboard (gleicher Kachel-Typ-Satz: Wert/Ja-Nein/Schalter/
 * Ausloeser/Text/HTML/Gruppe).
 */
class SystemDashboard extends IPSModule
{
    use \Fuchsbau\TileKit\SystemDashboard\TileKit;

    private const CHART_SPAN_SEC = 6 * 3600; // 6h, wie die anderen Dashboards

    /**
     * Keine Vorbelegung moeglich -- welche HomeMatic-Systemvariablen,
     * Device-Monitor-/Netzwerk-/FritzBox-Objekte auf dieser Installation
     * existieren, ist individuell. Die Liste startet daher nur mit
     * Gruppen-Ueberschriften als Geruest; der Nutzer ergaenzt die
     * passenden Zeilen selbst (Variable/Instanz per Auswahl-Dialog).
     */
    private const DEFAULT_TILES = [
        ['name' => 'HomeMatic Systemvariablen', 'variable' => 0, 'script' => 0, 'type' => 'group'],
        ['name' => 'Geräte-Monitor',             'variable' => 0, 'script' => 0, 'type' => 'group'],
        ['name' => 'Netzwerk',                   'variable' => 0, 'script' => 0, 'type' => 'group'],
        ['name' => 'FritzBox',                   'variable' => 0, 'script' => 0, 'type' => 'group'],
    ];

    // ─── Lifecycle ──────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('tiles', json_encode(self::DEFAULT_TILES));
        $this->RegisterPropertyInteger('archive_instance', 0);
        $this->RegisterPropertyInteger('update_interval', 60);

        $this->RegisterTimer('UpdateTimer', 0, 'SYD_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $tiles = json_decode($this->ReadPropertyString('tiles'), true) ?: [];
        $realTiles = array_filter($tiles, fn ($t) => ($t['type'] ?? '') !== 'group');
        if (count($realTiles) === 0) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);
        $this->Refresh();
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    public function Refresh(): void
    {
        try {
            $this->tkPush('__all__', $this->collectData());
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('SystemDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── IPS action handler ─────────────────────────────────────────────────────

    public function RequestAction($Ident, $Value): void
    {
        try {
            if (preg_match('/^tile_(\d+)_toggle$/', (string) $Ident, $m)) {
                $this->forwardToggle((int) $m[1], (bool) $Value);
                return;
            }
            if (preg_match('/^tile_(\d+)_action$/', (string) $Ident, $m)) {
                $this->forwardAction((int) $m[1]);
                return;
            }
            $this->LogMessage("SystemDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        } catch (\Throwable $e) {
            $this->LogMessage('SystemDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /**
     * Schaltet die Zielvariable. Variablen mit registrierter Action (z.B.
     * FritzBox-Schalter, HomeMatic-Kanaele) werden ueber RequestAction
     * geschaltet; reine Systemvariablen ohne Action (z.B. HomeMatic-
     * Systemvariablen, die sonst nur von einem Skript per SetValue
     * gesetzt werden) bekommen den Wert direkt per Kernel-Write, analog
     * zum Home/Room Dashboard.
     */
    private function forwardToggle(int $index, bool $value): void
    {
        $tiles  = json_decode($this->ReadPropertyString('tiles'), true) ?: [];
        $nodeId = (int) ($tiles[$index]['variable'] ?? 0);
        $target = $this->resolveToggleTarget($nodeId);
        if ($target <= 0) {
            return;
        }
        $variable = @IPS_GetVariable($target);
        if ($variable !== false && (int) $variable['VariableAction'] > 0) {
            RequestAction($target, $value);
            return;
        }
        SetValueBoolean($target, $value);
    }

    private function forwardAction(int $index): void
    {
        $tiles    = json_decode($this->ReadPropertyString('tiles'), true) ?: [];
        $scriptId = $this->resolveScriptId((int) ($tiles[$index]['script'] ?? 0));
        if ($scriptId <= 0) {
            return;
        }
        @IPS_RunScriptEx($scriptId, []);
    }

    /**
     * Akzeptiert fuer den Schalter-Typ entweder direkt eine Geraete-Instanz
     * (Ein/Aus-Ident wird per Konvention als 'STATE' angenommen), eine
     * rohe Boolean-Variable, oder ein Link-Objekt auf eines der beiden
     * (Links werden rekursiv aufgeloest, da IPS_GetVariable/IPS_GetInstance
     * anders als GetValue/RequestAction nicht transparent durch Links
     * hindurchgreifen).
     */
    private function resolveToggleTarget(int $nodeId): int
    {
        if ($nodeId <= 0) {
            return 0;
        }
        if (@IPS_InstanceExists($nodeId)) {
            $id = @IPS_GetObjectIDByIdent('STATE', $nodeId);
            return $id ?: 0;
        }
        if (@IPS_LinkExists($nodeId)) {
            return $this->resolveToggleTarget((int) IPS_GetLink($nodeId)['TargetID']);
        }
        return @IPS_VariableExists($nodeId) ? $nodeId : 0;
    }

    /** Loest ein Link-Objekt rekursiv zur echten Skript-ID auf. */
    private function resolveScriptId(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        if (@IPS_LinkExists($id)) {
            return $this->resolveScriptId((int) IPS_GetLink($id)['TargetID']);
        }
        return @IPS_ScriptExists($id) ? $id : 0;
    }

    /** Root-Link-Objekte auf Variablen sind fuer GetValue/GetValueFormatted transparent, aber NICHT fuer IPS_GetVariable() (Profil-/Farb-Lookup) -- deshalb hier einmal zentral aufloesen. */
    private function resolveVariableId(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        if (@IPS_LinkExists($id)) {
            return $this->resolveVariableId((int) IPS_GetLink($id)['TargetID']);
        }
        return @IPS_VariableExists($id) ? $id : 0;
    }

    // ─── Data collection ──────────────────────────────────────────────────────

    private function collectData(): array
    {
        $tiles = json_decode($this->ReadPropertyString('tiles'), true) ?: [];
        $out   = [];
        foreach ($tiles as $i => $tile) {
            $out[] = $this->collectTile($i, $tile);
        }
        return ['tiles' => $out, 'updated' => date('d.m. H:i')];
    }

    private function collectTile(int $index, array $tile): array
    {
        $type  = $tile['type'] ?? 'value';
        $varId = $this->resolveVariableId((int) ($tile['variable'] ?? 0));
        $ident = 'tile_' . $index;

        $data = [
            'ident' => $ident,
            'name'  => (string) ($tile['name'] ?? ''),
            'type'  => $type,
        ];

        switch ($type) {
            case 'value':
                $data['text']  = self::tkFormatted($varId);
                $data['chart'] = $varId > 0 ? self::tkSparkline(self::tkHistory($this->ReadPropertyInteger('archive_instance'), $varId, self::CHART_SPAN_SEC), self::CHART_SPAN_SEC) : '';
                break;
            case 'bool':
                $value         = $varId > 0 ? (bool) GetValue($varId) : null;
                $data['text']  = self::tkFormatted($varId);
                $data['color'] = $varId > 0 ? self::tkAssocColor($varId) : null;
                $data['icon']  = self::tkTopicIcon($data['name'], $value);
                break;
            case 'toggle':
                $target          = $this->resolveToggleTarget((int) ($tile['variable'] ?? 0));
                $data['checked'] = $target > 0 ? (bool) GetValue($target) : false;
                $data['text']    = self::tkFormatted($target);
                break;
            case 'action':
                // reiner Ausloeser, nichts zu lesen
                break;
            case 'string':
                $data['text'] = self::tkFormatted($varId, '');
                break;
            case 'html':
                $data['html'] = $varId > 0 ? (string) GetValue($varId) : '';
                break;
        }
        return $data;
    }

    // ─── Rendering (TileKit, siehe docs/ui-kachel.md im Workspace) ─────────────

    private function buildDashboardHTML(): string
    {
        $d    = $this->collectData();
        $body = '';
        foreach ($d['tiles'] as $tile) {
            $body .= $this->renderTile($tile);
        }
        $js = <<<'JS'
var lastHtml = {};
(state.tiles || []).forEach(function (t) { if (t.type === 'html') lastHtml[t.ident] = t.html; });
function updateTile(t) {
  if (t.type === 'value') {
    tk.setText(t.ident + '_val', t.text);
    var poly = tk.el(t.ident + '_poly');
    if (poly && t.chart) poly.setAttribute('points', t.chart);
  } else if (t.type === 'bool') {
    tk.setText(t.ident + '_val', t.text);
    tk.setColor(t.ident + '_status', t.color);
    tk.setText(t.ident + '_icon', t.icon || '');
  } else if (t.type === 'toggle') {
    tk.setText(t.ident + '_val', t.text);
    tk.setOn(t.ident + '_card', t.checked);
  } else if (t.type === 'string') {
    tk.setText(t.ident + '_val', t.text);
  } else if (t.type === 'html' && lastHtml[t.ident] !== t.html) {
    // Nur bei geaendertem Inhalt neu laden (sonst flackert das iframe bei jedem Push)
    lastHtml[t.ident] = t.html;
    var f = tk.el(t.ident + '_frame');
    if (f) f.srcdoc = f.srcdoc.replace(/<\/style>[\s\S]*$/, '</style>') + t.html;
  }
}
tk.on('__all__', function (v) { state = v; v.tiles.forEach(updateTile); });
JS;
        return $this->tkPage($this->Translate('System Dashboard'), '⚙️', $d['updated'], "<div class=\"tk-grid\">{$body}</div>", '', $js, json_encode($d));
    }

    private function renderTile(array $tile): string
    {
        $ident = $tile['ident'];
        $name  = $tile['name'];
        switch ($tile['type']) {
            case 'group':
                return self::tkGroup($name);
            case 'value':
                return self::tkValueTile($ident, $name, $tile['text'], $tile['chart']);
            case 'bool':
                return self::tkStatusTile($ident, $name, $tile['text'], $tile['icon'] ?? '', '', $tile['color'] ?? null);
            case 'toggle':
                return self::tkToggleTile($ident, $name, (bool) $tile['checked'], $tile['text']);
            case 'action':
                return self::tkActionTile($ident, $ident . '_action', $name);
            case 'string':
                return self::tkStatusTile($ident, $name, $tile['text']);
            case 'html':
                return self::tkHtmlTile($ident, $name, $tile['html']);
            default:
                return '';
        }
    }
}
