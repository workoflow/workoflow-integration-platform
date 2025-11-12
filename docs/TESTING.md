# Testing Guide

## Überblick

Die Workoflow Integration Platform verwendet PHPUnit für automatisierte Tests. Die Test-Suite ist in drei Hauptkategorien unterteilt: Unit Tests, Integration Tests und Acceptance Tests.

## Test-Umgebung

### Konfiguration

Die Test-Umgebung wird über folgende Dateien konfiguriert:

- `.env.test` - Standard Test-Umgebungsvariablen
- `.env.test.local` - Lokale Überschreibungen (nicht versioniert)
- `phpunit.dist.xml` - PHPUnit Konfiguration

### Datenbank Setup

Tests verwenden eine separate Test-Datenbank. Die Verbindung wird in `.env.test` konfiguriert:

```env
DATABASE_URL="mysql://user:password@mariadb:3306/workoflow_test?serverVersion=11.2.2-MariaDB"
```

## Test-Struktur

```
tests/
├── Unit/               # Unit Tests für isolierte Komponenten
├── Integration/        # Integration Tests für Controller & Services
│   ├── Controller/    # Controller Integration Tests
│   └── AbstractIntegrationTestCase.php  # Basis-Klasse
└── Acceptance/        # End-to-End Tests (optional)
```

## Fixtures

Test-Daten werden über Doctrine Fixtures bereitgestellt:

### Fixtures laden

```bash
# Einmalig vor allen Tests
php bin/console doctrine:fixtures:load --env=test --no-interaction
```

### Fixture-Struktur

```php
// src/DataFixtures/OrganisationTestFixtures.php
class OrganisationTestFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Test-Organisationen
        $testOrganisation = new Organisation();
        $testOrganisation->setName('Test Organisation');

        // Test-Benutzer
        $adminUser = new User();
        $adminUser->setEmail('admin@test.example.com');
        $adminUser->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        // Test-Integrationen
        $jiraConfig = new IntegrationConfig();
        $jiraConfig->setName('Test JIRA Active');
        $jiraConfig->setIntegrationType('jira');
    }
}
```

### Vordefinierte Test-Benutzer

| Email | Rolle | Organisation | Beschreibung |
|-------|-------|--------------|--------------|
| `admin@test.example.com` | ROLE_ADMIN | Test Organisation | Administrator |
| `member@test.example.com` | ROLE_USER | Test Organisation | Normaler Benutzer |
| `other@test.example.com` | ROLE_USER | Other Organisation | Benutzer andere Org |

## Integration Tests

### Basis-Klasse

Alle Integration Tests erben von `AbstractIntegrationTestCase`:

```php
abstract class AbstractIntegrationTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();
    }

    protected function loginUser(string $email, ?int $organisationId = null): void
    {
        // Benutzer einloggen und Organisation setzen
    }

    protected function createTestIntegrationConfig(...): IntegrationConfig
    {
        // Test-Integration erstellen
    }
}
```

### Controller Test Beispiel

```php
class IntegrationControllerTest extends AbstractIntegrationTestCase
{
    public function testCreateNewJiraIntegration(): void
    {
        // 1. Bereinigung
        $existing = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test Integration']);
        if ($existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        // 2. Formular aufrufen
        $crawler = $this->client->request('GET', '/integrations/setup/jira');

        // 3. Formular ausfüllen
        $form = $crawler->selectButton('Konfiguration speichern')->form();
        $form['name'] = 'Test Integration';
        $form['url'] = 'https://test.atlassian.net';
        $form['username'] = 'test@example.com';
        $form['api_token'] = 'test-token';

        // 4. Formular absenden
        $this->client->submit($form);

        // 5. Assertions
        $this->assertResponseRedirects('/integrations/');

        // 6. Datenbank prüfen
        $config = $this->entityManager
            ->getRepository(IntegrationConfig::class)
            ->findOneBy(['name' => 'Test Integration']);

        $this->assertNotNull($config);
        $this->assertTrue($config->isActive());
    }
}
```

## Test-Ausführung

### Alle Tests ausführen

```bash
# Fixtures laden
php bin/console doctrine:fixtures:load --env=test --no-interaction

# Alle Tests
php bin/phpunit

# Nur Integration Tests
php bin/phpunit tests/Integration/

# Einzelner Test
php bin/phpunit --filter testCreateNewJiraIntegration
```

### Code-Qualität prüfen

```bash
# PHPStan und CodeSniffer
composer code-check

# Nur PHPStan
composer phpstan

# Nur CodeSniffer
composer phpcs
```

## Häufige Probleme und Lösungen

### Problem: Entity Manager Probleme

**Symptom:** "Entity is not managed" Fehler

**Lösung:**
```php
// Entity Manager clearen für frische Daten
$this->entityManager->clear();

// Entity neu laden
$config = $this->entityManager
    ->getRepository(IntegrationConfig::class)
    ->find($configId);
```

### Problem: Doppelte Test-Daten

**Symptom:** "Duplicate entry" oder "already exists" Fehler

**Lösung:**
```php
// Vor dem Test bereinigen
$existing = $this->entityManager
    ->getRepository(IntegrationConfig::class)
    ->findOneBy(['name' => 'Test Name']);
if ($existing) {
    $this->entityManager->remove($existing);
    $this->entityManager->flush();
}
```

### Problem: Authentication in Tests

**Symptom:** Redirect zu /login statt erwarteter Seite

**Lösung:**
```php
protected function setUp(): void
{
    parent::setUp();
    // Benutzer einloggen
    $this->loginUser('admin@test.example.com');
}
```

### Problem: Formular-Validierung schlägt fehl

**Symptom:** Formular wird nicht submitted, keine Weiterleitung

**Debug-Strategie:**
```php
// Response Status prüfen
$response = $this->client->getResponse();
if (!$response->isRedirect()) {
    // Fehler suchen
    $crawler = $this->client->getCrawler();
    $alerts = $crawler->filter('.alert');
    if ($alerts->count() > 0) {
        var_dump('Alert:', $alerts->text());
    }
}
```

## Best Practices

### 1. Test-Isolation

Jeder Test sollte unabhängig laufen:
- Eigene Test-Daten erstellen
- Nach dem Test aufräumen
- Keine Abhängigkeiten zwischen Tests

### 2. Aussagekräftige Assertions

```php
// Gut - spezifische Fehlermeldung
$this->assertNotNull($config, 'Integration config should be created');

// Schlecht - keine Kontext-Information
$this->assertNotNull($config);
```

### 3. Test-Daten Management

```php
class IntegrationControllerTest extends AbstractIntegrationTestCase
{
    protected function tearDown(): void
    {
        // Aufräumen nach jedem Test
        parent::tearDown();

        // Entity Manager schließen
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }
    }
}
```

### 4. Sprach-Konsistenz

Die Anwendung läuft primär auf Deutsch:
```php
// Deutsch
$this->assertPageTitleContains('Integrationen');
$this->assertSelectorTextContains('h1', 'Einrichten Jira');

// NICHT Englisch (außer bei englischen Feldern)
$this->assertPageTitleContains('Integrations');  // ❌
```

## Test-Coverage

### Aktueller Status

- **Unit Tests:** Ausstehend
- **Integration Tests:** 8/18 Tests aktiv
- **E2E Tests:** 4 Tests für JIRA Service aktiv
- **Acceptance Tests:** Ausstehend

### E2E Tests für Externe Services

E2E-Tests validieren die Integration mit echten externen Services:

#### JIRA E2E Tests

**Datei:** `tests/Integration/Service/JiraServiceE2ETest.php`

**Voraussetzungen:**
- Echte JIRA-Credentials in `.env.test.local`
- Zugriff auf JIRA Cloud Instanz

**Tests:**
- Connection Validation
- Issue Retrieval mit Content-Validierung
- JQL Search mit Pagination
- Detailed Error Reporting

**Ausführung:**
```bash
php bin/phpunit tests/Integration/Service/JiraServiceE2ETest.php --testdox
```

### Übersprungene Tests

Einige Tests sind temporär deaktiviert:

| Test | Grund | Lösung |
|------|-------|---------|
| Toggle Tests | User-Vergleich per Referenz | Controller Fix benötigt |
| Form Tests | Validierungs-Details | Weitere Untersuchung |
| Connection Tests | Externe API benötigt | Mock implementieren |

## CI/CD Integration

### GitHub Actions Beispiel

```yaml
name: Tests
on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install Dependencies
        run: composer install

      - name: Setup Test Database
        run: |
          php bin/console doctrine:database:create --env=test
          php bin/console doctrine:schema:update --force --env=test
          php bin/console doctrine:fixtures:load --env=test --no-interaction

      - name: Run Tests
        run: php bin/phpunit

      - name: Code Quality
        run: composer code-check
```

## Debugging

### Verbose Output

```bash
# Mehr Details bei Fehlern
php bin/phpunit -v

# Noch mehr Details
php bin/phpunit -vvv
```

### Einzelnen Test debuggen

```bash
# Mit Filter
php bin/phpunit --filter testCreateNewJiraIntegration

# Mit Pfad
php bin/phpunit tests/Integration/Controller/IntegrationControllerTest.php::testCreateNewJiraIntegration
```

### Xdebug Integration

Für Step-Debugging mit PHPStorm/VSCode:
```bash
XDEBUG_CONFIG="idekey=PHPSTORM" php bin/phpunit
```

## Nützliche Befehle

```bash
# Test-Datenbank neu erstellen
php bin/console doctrine:database:drop --force --env=test
php bin/console doctrine:database:create --env=test
php bin/console doctrine:schema:update --force --env=test

# Fixtures neu laden
php bin/console doctrine:fixtures:load --env=test --no-interaction

# Cache leeren
php bin/console cache:clear --env=test

# Nur fehlgeschlagene Tests wiederholen
php bin/phpunit --stop-on-failure

# Test-Coverage generieren (wenn konfiguriert)
php bin/phpunit --coverage-html coverage/

# E2E Tests ausführen
php bin/phpunit tests/Integration/Service/JiraServiceE2ETest.php --testdox

# Einzelnen E2E-Test mit Filter
php bin/phpunit tests/Integration/Service/JiraServiceE2ETest.php --filter testGetIssueAzubi20WithValidation
```

## Weiterführende Ressourcen

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Symfony Testing Documentation](https://symfony.com/doc/current/testing.html)
- [Doctrine Fixtures Bundle](https://symfony.com/bundles/DoctrineFixturesBundle/current/index.html)
- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)