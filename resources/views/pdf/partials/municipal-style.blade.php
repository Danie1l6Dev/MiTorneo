body {
    font-family: 'Times New Roman', Times, serif;
    color: #000;
    font-size: 15px;
}

header {
    position: fixed;
    top: -170px;
    left: 0;
    right: 0;
    height: 170px;
    text-align: center;
}

header table {
    width: 100%;
}

header .crest img {
    height: 110px;
}

header .wordmark {
    vertical-align: middle;
    padding: 0 10px;
}

header .wordmark img {
    width: 100%;
}

header .letterhead-line {
    font-size: 12px;
    line-height: 1.5;
}

header .letterhead-line.strong {
    font-weight: bold;
    font-size: 13px;
}

footer {
    position: fixed;
    bottom: -40px;
    left: 0;
    right: 0;
    height: 40px;
    text-align: center;
}

h1 {
    text-align: center;
    font-size: 20px;
    font-weight: bold;
    margin: 4px 0 2px 0;
    text-transform: uppercase;
}

h2 {
    text-align: center;
    font-size: 16px;
    font-weight: bold;
    margin: 0 0 18px 0;
    text-transform: uppercase;
}

h3 {
    font-size: 16px;
    font-weight: bold;
    margin: 0 0 4px 0;
    text-transform: uppercase;
}

.meta {
    font-size: 15px;
    margin-bottom: 5px;
}

.meta strong {
    text-transform: uppercase;
}

.category-section + .category-section {
    page-break-before: always;
}

table.standings {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 22px;
}

table.standings caption {
    text-align: left;
    font-weight: bold;
    font-size: 15px;
    text-transform: uppercase;
    padding: 8px 0;
}

table.standings th,
table.standings td {
    border: 1px solid #000;
    padding: 2px 6px;
    font-size: 15px;
}

table.standings th {
    font-weight: bold;
    text-transform: uppercase;
    text-align: center;
}

table.standings td.team {
    text-align: left;
}

table.standings td.num {
    text-align: center;
}

.signature {
    margin-top: 40px;
    text-align: center;
}

.signature img {
    height: 56px;
}

.signature .line {
    display: block;
    width: 240px;
    margin: -10px auto 4px auto;
    border-top: 1px solid #000;
}

.signature .role {
    font-weight: bold;
    text-transform: uppercase;
    font-size: 13px;
}

/* Generic MiTorneo letterhead (no personal/federation branding) */
header.default {
    top: -95px;
    height: 90px;
    text-align: left;
}

header.default .crest img {
    height: 56px;
}

header.default .brand {
    vertical-align: middle;
    padding-left: 8px;
}

header.default .brand-name {
    font-family: Helvetica, Arial, sans-serif;
    font-size: 26px;
    font-weight: bold;
    color: #1f2937;
}

header.default .brand-tagline {
    font-family: Helvetica, Arial, sans-serif;
    font-size: 12px;
    color: #6b7280;
}

header.default .brand-rule {
    border-top: 2px solid #1f2937;
    margin-top: 8px;
}

footer.default {
    bottom: -35px;
    height: 30px;
    font-family: Helvetica, Arial, sans-serif;
    font-size: 10px;
    color: #6b7280;
}
