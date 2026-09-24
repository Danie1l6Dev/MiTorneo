{{-- Loaded after municipal-style: same serif/black-border look as the standings tables, denser for match lists. --}}
.phase-section + .phase-section {
    page-break-before: always;
}

.round-section {
    margin-top: 16px;
}

/* Every jornada/round after the first starts on its own page (adjacent-sibling
   form, same reason documented for .category-section in municipal-style). */
.round-section + .round-section {
    page-break-before: always;
}

.round-title {
    font-size: 15px;
    font-weight: bold;
    text-transform: uppercase;
    border-bottom: 2px solid #000;
    padding-bottom: 2px;
    margin-bottom: 6px;
}

.round-title .subtitle {
    font-weight: normal;
    font-size: 13px;
}

.block-label {
    font-size: 13px;
    font-weight: bold;
    text-transform: uppercase;
    margin: 8px 0 4px 0;
}

table.results {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6px;
}

table.results th,
table.results td {
    border: 1px solid #000;
    padding: 3px 5px;
    font-size: 12px;
    vertical-align: middle;
}

table.results th {
    font-weight: bold;
    text-transform: uppercase;
    text-align: center;
    font-size: 11px;
}

table.results tr {
    page-break-inside: avoid;
}

table.results td.team-home {
    text-align: right;
    font-weight: bold;
}

table.results td.team-away {
    text-align: left;
    font-weight: bold;
}

table.results td.center {
    text-align: center;
}

table.results td.score {
    text-align: center;
    font-weight: bold;
    font-size: 13px;
    white-space: nowrap;
}

table.results .small {
    display: block;
    font-weight: normal;
    font-size: 10px;
}

table.results td.events {
    font-size: 11px;
    padding: 2px 6px 4px 6px;
    vertical-align: top;
}

table.results td.events-home {
    text-align: right;
}

table.results td.events-away {
    text-align: left;
}

table.results.match-table {
    margin-bottom: 28px;
    page-break-inside: avoid;
}

table.results td.info-row {
    text-align: center;
    font-size: 11px;
    font-style: italic;
    padding: 1px 6px 2px 6px;
}

.resting {
    font-size: 12px;
    font-style: italic;
    margin-bottom: 6px;
}

.legend {
    margin-top: 14px;
    font-size: 11px;
}

.empty-message {
    margin-top: 20px;
    font-style: italic;
    text-align: center;
}

/* Single-match report */
table.scoreboard {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0 4px 0;
    border: 1px solid #000;
}

table.scoreboard td {
    padding: 10px 8px;
    vertical-align: middle;
}

table.scoreboard td.team {
    width: 40%;
    font-size: 17px;
    font-weight: bold;
    text-transform: uppercase;
}

table.scoreboard td.team-home {
    text-align: right;
}

table.scoreboard td.team-away {
    text-align: left;
}

table.scoreboard .side-label {
    display: block;
    font-size: 11px;
    font-weight: normal;
}

table.scoreboard td.score {
    width: 20%;
    text-align: center;
    font-size: 28px;
    font-weight: bold;
    border-left: 1px solid #000;
    border-right: 1px solid #000;
}

.score-details {
    text-align: center;
    font-size: 13px;
    margin-bottom: 4px;
}

.report-section-title {
    font-size: 14px;
    font-weight: bold;
    text-transform: uppercase;
    margin: 18px 0 6px 0;
    border-bottom: 1px solid #000;
    padding-bottom: 2px;
}

table.rosters {
    width: 100%;
    border-collapse: collapse;
}

table.rosters > tbody > tr > td {
    width: 50%;
    vertical-align: top;
    padding: 0;
}

table.rosters > tbody > tr > td.gap {
    width: 2%;
}

table.roster {
    width: 100%;
    border-collapse: collapse;
}

table.roster caption {
    text-align: left;
    font-weight: bold;
    font-size: 12px;
    text-transform: uppercase;
    padding: 0 0 3px 0;
}

table.roster th,
table.roster td {
    border: 1px solid #000;
    padding: 2px 4px;
    font-size: 10.5px;
}

table.roster th {
    font-weight: bold;
    text-align: center;
}

table.roster td.num {
    text-align: center;
    width: 8%;
}

table.roster tr.coach td {
    font-style: italic;
}
