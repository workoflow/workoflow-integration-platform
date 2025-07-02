Bau mir eine Applikation (nutze gerne third party libraries via composer um den code schlank zu halten), achte auf Qualität, Performance und Stabilität. Die Applikation soll production ready sein.

Grob Konzept:
Die Applikation besteht aus zwei Kern Funktionalitäten
 - Ein User Interface zur Anbindung unterschiedlicher Integrationen
 - Einer automatisch generierten MCP Server URL (/sse) mit Authentifizierung (administrativer Zugriff, nicht pro User) und gefiltert über eine einkommende ID
    - Der dynamische Anteil dieser URL ist pro Organisation und wird von der Applikation generiert
    - Das ist der komplexe Teil dieser Applikation, die Tools im MCP Server werden dynamisch anhand eines übergebenen Parameters ermittelt 

User Journey - OnBoarding (Neue Registrierung):
 1. Login via Google OAuth2
 2. Dann muss eine Organisation angegeben werden
   - bis diese gepflegt wurde, ist der Rest der Applikation blockiert (er wird immer wieder aufgefordert die Organisation anzugeben)
   - z.B. ist es ab dann möglich Mitglieder anzulegen
   - oder es ist dann möglich Integrationen zu pflegen

User Journey - Integration Setup:
1. User navigiert zu "Integrationen"
2. Wählt "Neue Integration hinzufügen"
3. Wählt Integration-Typ (z.B. Jira)
4. Gibt Credentials ein (URL, API Token)
5. Testet die Verbindung
6. Aktiviert gewünschte Funktionen
7. Speichert mit eindeutiger ID für Workoflow Bot

Beschreibung Login:
 - In der .env ist bereits eine GOOGLE_CLIENT_ID, bitte stelle einen oauth2 Login bereit
 - Anschließend wird in der Datenbank eine User Entity angelegt inklusive access_token oder was sonst aus deiner Sicht nötig ist

Beschreibung Organisation:
 - Eine User hat immer genau eine Organisation hinterlegt
 - Alle eingeladenenen User sind automatisch ebenfalls Teil dieser Organisation
 - Ein User kann nur genau einer Organisation zugewiesen sein
 - User mit der Rolle Admin können den Namen der Organisation ändern

Beschreibung User:
 - Ein User kann die Rolle Admin oder Member haben
 - Daten: Name, Email (unique), Organisation (N)

Beschreibung Integrationen:
 - Integrationen werden pro User verwaltet
 - Über Integrationen steuern User Zugriffe auf Drittsysteme
 - Die vom User aktivierten oder deaktivierten Integrationen sind über den organisationsspezifischen MCP Server erreichbar und durch werden von AI Drittsystemen Systemen verwendet
 - Integrationen also deren hinterlegte Konfigurationen lassen sich aktivieren und deaktivieren
 - Alle hinterlegten Secrets werden verschlüsselt gespeichert
 - Es kann mehrere Konfigurationen pro Integration geben (z.B. zwei unterschiedlicher konfigurierte Jira Integrationen)
 - Es gibt folgende Standard Integrationen:
   - Jira
     - URL
     - API Token
     - Verfügbare Funktionen (jira_search, jira_get_issue, jira_get_sprints_from_board, jira_get_sprint_issues)
     - ID (Workoflow UserID -> Mit dem Hinweis das die ID über die Workoflow Prompt "Wie ist meine ID?" ermittelt werden kann)
   - Conflueence
     - URL
     - API Token
     - Verfügbare Funktionen (confluence_search, confluence_get_page, confluence_get_comments)
     - ID (Workoflow UserID -> Mit dem Hinweis das die ID über die Workoflow Prompt "Wie ist meine ID?" ermittelt werden kann)
 - Die Standard Integrationen baust du selbst
   - Die verfügbaren Funktionen definieren welche Tools der MCP Server hat
   - Die verfügbaren Funktionen werden von dir selbst gebaut (z.B. JIRA API Anbindung)
   - Die verfügbaren Funktionen lassen sich aktivieren oder deaktivieren
   - Teil einer jeden Funktion ist auch eine Beschreibung damit der konsumierende AI Agent, wenn er den MCP Server verwendet, weiß wann er dieses Tool benutzen soll. Siehe auch (Beschreibung MCP Funktionalität)
 - Es wird gespeichert und angezeigt wann zuletzt auf diese User angelegte Integration zugegriffen wurde. Stichwort "letzte Aktivität"
   - Der Zugriff passiert über den organisationsspezifischen MCP Server

Beschreibung Rollen:
 - Es gibt zwie Rollen, Admin und Member
 - Abhängig der User zugewiesenen Rolle, steuern diese die Sichtbarkeit und schränken die Funktionalitäten ein
 - Ein Admin User darf den Namen der Organisation ändern

Beschreibung Rolle - Admin:
 - Darf neue User zu seiner Organisation einladen
 - Ein Admin User kann die Rolle von einem eingeladenen User von Member auf Admin ändern

Beschreibung User Einladen:
 - Ein User wird über seine Emailadresse von einem Admin User eingeladen und erhält automatisch die Rolle Member

Beschreibung File Management:
 - Es gibt ein File Management auf Organisationsebene (Daten abgelegt und gelesen aus dem Bucket mit der env var MINIO_BUCKET)
 - Dort werden uploads aus s3 (minio) angezeigt
 - diese Lassen sich löschen oder neue Dateien können hochgeladen werden (multi upload per drag&drop)

Beschreibung MCP Funktionalität:
 - Die MCP URL wird pro Organisation generiert: /api/mcp/{org-uuid}/sse
 - Der MCP Server implementiert das Model Context Protocol (Anthropic Standard)
 - Authentifizierung erfolgt via Basic Auth (User: workoflow, Password: workoflow)
 - Der ?id Parameter identifiziert den spezifischen User innerhalb der Organisation
 - Beispiel: /api/mcp/7b8c1308-abf3-445f-a330-969ca97333ce/sse?id=user123
 - Die zurückgegebenen Tools basieren auf den aktivierten Integrationen des Users

Beispiel Tool-Beschreibungen für AI Agent:
- jira_search_issues: "Sucht nach Jira Issues mit JQL. Nutze dies wenn der User nach Tickets, Bugs oder Tasks fragt."
- jira_create_issue: "Erstellt ein neues Jira Issue. Nutze dies wenn der User ein neues Ticket anlegen möchte."
- confluence_search_pages: "Durchsucht Confluence Seiten. Nutze dies für Dokumentations-Anfragen."

Zusatz Informationen:
 - ich habe dir hier bereits ein Docker Setup und composer.json bereitgestellt - bitte verwenden oder anpassen / erweitern
 - Integriere ausschließlich die letzten verfügbaren Versionsstände der integrierten Technologien
   - suche wenn nötig selbst nach notwendigen Dokumentationen um es hier richtig einzubauen
 - Erstelle mir am Ende eine CLAUDE.md die alles was gebaut wurde zusammenfasst. Aktualisiere diese zukünftig eigenständig damit du ein memory hast
 - Applikationsparameter sind per .env pflegbar, füge wenn nötig neue Optionen hinzu
 - halte die .env.dist in sync zur .env
 - Alle Texte mehrsprachig halten, Sprachen: deutsch und englisch (also keine statischen einsetzen)
 - Credentials immer verschlüsselt speichern
 - Audit Logging für alle API-Zugriffe
 - Daten die der User pflegt müssen auch nachträglich änderbar sein (ggf abhängig seiner Rolle)
 - Erlaube es das er seinen Account löscht, alle dort verknüpften Daten werden dann entfernt

Installation:
 - Für das spätere Übertragen auf die PROD Umgebung, stelle mir bitte eine setup.sh bereit
   - diese stellt sicher das dass Setup und die Applikation korrekt installiert werden
   - dieses script wird auf einem root server ausgeführt, wo ich als docker user ein docker setup starten kann indem ich dann commands ausführe
   - die PROD Umgebung unterstützt nur external volumnes, deshalb muss ggf eine docker-compose-prod.yml erstellt werden

Beschreibung Error Handling:
- Fehlgeschlagene API Calls werden geloggt
- User erhält Benachrichtigung bei kritischen Fehlern
- Automatische Retry-Logic für transiente Fehler

Datenbank Migrations:
- In Development: Single Migration File wird überschrieben
- In Production: Neue Migration Files für Schema-Änderungen
- Setup.sh unterscheidet zwischen Dev und Prod Modus

Beschreibung File Management:
- S3-kompatibles MinIO für File Storage
- Maximale Dateigröße: 100MB
- Erlaubte Formate: PDF, DOCX, XLSX, CSV, TXT, JSON
- Automatische Virus-Scans vor Upload-Bestätigung
- Datei-Metadaten: Uploader, Upload-Zeit, Größe, MIME-Type

Testing:
- füge einen Parameter X-Test-Auth-Email hinzu
    - wird dieser verwendet ist ein user direkt zur übergebenen Identität angemeldet
    - wird der Parameter entfernt, bleibt man angemeldet
- Teste jedes Feature selbst über die beiden hinterlegten MCP Server mit den Namen:
    - puppeteer
    - mariadb
- Da die Application in docker Containern läuft, musst du deine Tests gegen die docker container machen
- Der X-Test-Auth-Email Header umgeht OAuth für Testumgebungen
- Zwei Test-User mit vorkonfigurierten Integrationen:
    - puppeteer.test1@example.com (Admin mit Jira + Confluence)
    - puppeteer.test2@example.com (Member mit nur Jira)
- MCP Server Tests prüfen:
    - Korrekte Tool-Filterung basierend auf User ID
    - Integration API Calls
 - Hier ein JIRA Test Account
   - JIRA_URL=https://nexus-netsoft.atlassian.net
   - CONFLUENCE_URL=https://nexus-netsoft.atlassian.net/wiki
   - JIRA_USERNAME=xxxx@nexus-netsoft.com
   - JIRA_API_TOKEN=xxxx-xxx-xxxx
   - Board: https://nexus-netsoft.atlassian.net/jira/software/c/projects/NST/boards/487
   - Story: https://nexus-netsoft.atlassian.net/browse/NST-6
   - Example page: https://nexus-netsoft.atlassian.net/wiki/spaces/AT/pages/4781637714/2021-08-24+Meeting+notes
Tech Stack:
 - Docker
 - PHP 8.4
 - frankenphp und symfony

Datenbank:
 - MariaDB (siehe .env)
 - Du bist zuständig ein normalisiertes klares Datenmodell zu erstellen, challenge dich selbst jederzeit und verwerfe falsche Design Entscheidungen - denn noch sind wir nicht live

External Services:
 - siehe .env

Für dich:
 - Wir haben heute den 02.07.2025
