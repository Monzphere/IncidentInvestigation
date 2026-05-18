# Zabbix Incident Investigation Module
![views](https://komarev.com/ghpvc/?username=matheusandrade&repo=https://github.com/Monzphere/IncidentInvestigation) 
![Stars](https://img.shields.io/github/stars/Monzphere/IncidentInvestigation?style=social)
![Forks](https://img.shields.io/github/forks/Monzphere/IncidentInvestigation?style=social)
![Issues](https://img.shields.io/github/issues/Monzphere/IncidentInvestigation)

<img width="1455" height="746" alt="image" src="https://github.com/user-attachments/assets/67e25ae3-e580-4939-af3a-0fcc35f9fe45" />
<img width="1451" height="752" alt="image" src="https://github.com/user-attachments/assets/87a2a4cd-f6f6-4b82-8545-ddf32c1812c7" />
<img width="1439" height="748" alt="image" src="https://github.com/user-attachments/assets/fd0222e2-7236-4600-95e1-540f8a26e585" />

A Zabbix module providing detailed incident investigation with temporal patterns, occurrence heatmaps, SLA impact, and action timeline.

**Developed by [Monzphere](https://monzphere.com)**

---

## What the module provides

- **Temporal patterns**: heatmap showing which weekdays and hours incidents occur most frequently
- **Monthly drill-down**: click a month to see daily distribution of incidents
- **Monthly comparison**: trend (↗ ↘ →) and percentage change vs previous month
- **SLA by service**: impact on affected services with SLI/SLO, visual gauge and compact tree
- **Timeline and Actions**: toggle between event list and actions table (alerts, commands, status) using native Zabbix layout
- **Recommendations**: highest-risk hours and days for planning
- **Quick comparison**: "Same slot last week" and "Same day last week" when filters are applied
- **Direct access**: magnifying glass icon (🔍) on the problem list and problem widgets that opens investigation for that event
- **Maintenance integration**: visualization of maintenance periods and their correlation with incidents (see [Maintenance integration](#maintenance-integration) below)

---

## Maintenance integration

The module displays Zabbix maintenances in the investigation views to help correlate incidents with planned maintenance windows.

### Where maintenances appear

- **Heatmap (day × hour)**: Cells that had incidents during a maintenance period show a wrench icon (🔧). The legend "Icon = incident during maintenance" explains this.
- **Last 12 months**: Each month has an orange bar below the incident bar when maintenances occurred in that month. The number inside the bar indicates how many maintenances. **Click** the orange bar to open a tooltip (Zabbix native style) with the list of maintenances and their date ranges.
- **Daily distribution (drilldown)**: When you click a month, each day shows segments. Orange segments indicate days with maintenance; the number shows how many maintenances that day. **Click** a segment to see details in a tooltip.

### How it works

- Maintenances are fetched for the host and host groups of the incident, for the last 12 months.
- Recurring timeperiods (daily, weekly, monthly) are expanded into concrete segments.
- Legends remain visible even when there are no maintenances in the current view.

### Permissions

- **Configuration → Maintenance** read access is required for maintenance data to be shown.

---

## Requirements

- Zabbix 7.0.x and 7.4.x (tested on both versions)
- PHP 8.0+
- Zabbix with Services and SLA configured (optional, for SLA impact panel)

If the module does not work on your Zabbix version, please open an [issue](https://github.com/Monzphere/IncidentInvestigation/issues) on GitHub.

---

## Installation

### 1. Download the module

```bash
git clone https://github.com/Monzphere/IncidentInvestigation.git
# or download the ZIP and extract
```

### 2. Copy to Zabbix

Copy the module folder to Zabbix's modules directory:

```bash
cp -r IncidentInvestigation /usr/share/zabbix/modules/
```

If the `modules` directory does not exist, create it:

```bash
mkdir -p /usr/share/zabbix/modules
cp -r IncidentInvestigation /usr/share/zabbix/modules/
```

### 3. Enable the module in Zabbix

1. Go to **Administration → General → Modules**
2. Find **Incident Investigation**
3. Click **Enable**

---

## How to use

The module does not add a menu item. Access is through:

- **From the problem list**: in **Monitoring → Problems**, click the magnifying glass icon (🔍) next to each problem to open the investigation for that event
- **From problem widgets**: the same icon appears on problem widgets in dashboards
- **Direct URL**: `zabbix.php?action=incident.investigation.view&eventid=12345` (replace with the desired eventid)

---

## Module structure

```
IncidentInvestigation/
├── Module.php
├── manifest.json
├── actions/
│   ├── CControllerIncidentInvestigationView.php
│   └── CControllerIncidentServiceImpact.php
├── assets/
│   ├── css/
│   │   ├── blue-theme.css
│   │   └── dark-theme.css
│   └── js/
│       └── problem-investigation-icon.js
├── views/
│   ├── incident.investigation.view.php
│   ├── layout.htmlpage.php
│   └── js/
│       └── incident.investigation.js.php
└── README.md
```

---

## License

Open source and free for the community.

---

## Acknowledgments

We thank our partner **[Lunio](https://luniobr.com)** for investing in collaboration toward open source and free solutions, contributing to the monitoring ecosystem and supporting the Zabbix community.

---

**Monzphere** – [monzphere.com](https://monzphere.com)
