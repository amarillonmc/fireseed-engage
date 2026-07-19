# fireseed-engage - Agent Guidelines

## Project Overview
A PHP web game (种火集结号) - an extension game mode for "Tokiwa Battle Royale" (常磐大逃杀). Strategic city-building with generals, armies, resource collection, and territory conquest.

## Tech Stack
- **Backend**: PHP 7.4/8.2 with MySQL/MariaDB
- **Frontend**: HTML5, CSS3, vanilla JavaScript (no frameworks)
- **Architecture**: Class-based OOP with MVC-ish pattern

## Build & Test Commands
```bash
# No build system - direct PHP execution
# Run via local web server:
php -S localhost:8000

# Database setup:
mysql -u root -p < sql/*.sql  # Import schema files

# Cron tasks (for resource production, training, etc.):
php cron_tasks.php  # Should be run periodically
```

**Testing**: No automated test suite. Manual testing through web interface.

## Code Style Guidelines

### PHP Files

**File Structure:**
- Single opening tag `<?php` at top, no closing tag
- Files start with Chinese comment: `// 种火集结号 - 文件说明`
- Include order: config → database → classes → functions

**Class Conventions:**
```php
class ClassName {
    private $db;
    private $property;      // camelCase
    private $isValid = false;

    public function __construct($id = null) {
        $this->db = Database::getInstance()->getConnection();
        if ($id !== null) {
            $this->loadData();
        }
    }

    /**
     * Chinese description / English description
     * @param type $name Description
     * @return type Description
     */
    public function methodName($param) {  // camelCase
        // Always use prepared statements
        $query = "SELECT * FROM table WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $param);
        $stmt->execute();
        $result = $stmt->get_result();
        // ...
    }
}
```

**Constants:**
- UPPER_SNAKE_CASE: `DB_HOST`, `SITE_NAME`, `MAP_WIDTH`
- Defined in `config/game_constants.php` (runtime constants) or `config/config.php`

**Database Access:**
- Always use `Database::getInstance()` singleton
- Always use prepared statements with bind_param
- Types: 'i'=int, 'd'=double, 's'=string, 'b'=blob
- Always close statements: `$stmt->close()`
- Installer portability exception: `install.php` may use `mysqli::query()` only for a single `PREPARE`, `EXECUTE`, `DEALLOCATE PREPARE`, `CREATE TRIGGER`, or `DROP TRIGGER` statement read verbatim from a repository-owned SQL file. These statements are not portably available through the binary prepared-statement protocol on every supported MySQL/MariaDB version. The dispatcher must use an explicit statement-type allowlist, and no user input, generated identifier, or runtime value may enter this path.

**Validation Pattern:**
```php
public function isValid() {
    return $this->isValid;
}

// Always check isValid() before operating on object
if (!$object->isValid()) {
    return false;
}
```

### API Endpoints (`api/`)

```php
<?php
require_once '../includes/init.php';
header('Content-Type: application/json');

// Session check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

// Response format
echo json_encode([
    'success' => true,
    'data' => [...],
    'message' => '描述 / Description'
]);
```

### HTML/PHP Templates

**Structure:**
```php
<?php
require_once 'includes/init.php';
// Session validation
$user = new User($_SESSION['user_id']);
$pageTitle = '页面标题';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>...</header>
        <main>
            <!-- Resources bar always at top -->
            <div class="resource-bar">...</div>
        </main>
        <footer>...</footer>
    </div>
    <script src="assets/js/script.js"></script>
</body>
</html>
```

### CSS (assets/css/style.css)

**Naming:**
- kebab-case: `.resource-bar`, `.city-cell`, `.generals-list`
- BEM-ish: `.city-cell.facility`, `.facility-name`
- State: `.active`, `.disabled`, `.empty`

**Organization:**
- Grid layouts for game boards
- Resource display styling consistent across pages
- Modal/dialog for user interactions

### JavaScript (assets/js/*.js)

**Pattern:**
```javascript
document.addEventListener('DOMContentLoaded', function() {
    // Event listeners
    element.addEventListener('click', function() {
        // ...
    });

    // Async API calls
    fetch('api/endpoint.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Success handling
        } else {
            showNotification(data.message);
        }
    })
    .catch(error => console.error('Error:', error));
});

// Helper functions
function showNotification(message) { /* ... */ }
function formatTime(seconds) { /* ... */ }
function numberFormat(number) { /* ... */ }
```

## Critical Rules (from .cursorrules)

1. **Bilingual Comments**: All code comments must be in Chinese AND English
2. **Bilingual Output**: Answers in Chinese first, then English
3. **No TODOs**: Never leave placeholders or missing pieces unless explicitly requested
4. **Readability**: Prioritize readability over performance
5. **Change Logs**: Record all changes in timestamped .txt files under `doc/etc/`
6. **Reference**: Check existing codebase patterns (User, City, Facility classes) before implementing new features

## Project Structure

```
/
├── config/           # Configuration (constants, database, game variables)
├── includes/
│   ├── classes/      # OOP classes (User, City, Facility, Army, etc.)
│   ├── functions.php # Helper functions
│   ├── database.php  # Database singleton
│   └── init.php      # Bootstrap file
├── api/              # JSON API endpoints
├── assets/
│   ├── css/          # Stylesheets
│   └── js/           # Frontend JavaScript
├── sql/              # Database schema files
├── doc/              # Documentation and change logs
└── *.php             # Page templates
```

## Security

- Passwords: Always use `password_hash($password, PASSWORD_DEFAULT)`
- SQL: Always use prepared statements - never concatenate strings
- Session: Validate on every page load, redirect to login.php if invalid
- Input: Sanitize with `Database::escapeString()` or prepared statements

## Game Constants

Key constants in `config/game_constants.php`:
- Map: 512x512 grid, center at (256, 256)
- Resources: 6 types (bright, warm, cold, green, day, night crystals)
- Circuit points: 48-hour production interval
- Soldier types: pawn, knight, rook, bishop, golem, scout
- Victory: 30 days to occupy special location

## When Working on This Codebase

1. **Read existing patterns** - User.php and City.php show standard class structure
2. **Bilingual comments mandatory** - Chinese and English for all comments
3. **Always use Database singleton** - Never create new connections
4. **API responses** - Always include 'success' boolean and 'message' field
5. **Session handling** - Copy from index.php for all protected pages
6. **Resource bar** - Include on all game pages
7. **Refer to game docs** - Check `doc/` folder for game mechanics

## Common Patterns

**Creating a new entity class:**
```php
class Entity {
    private $db;
    private $entityId;
    private $isValid = false;

    public function __construct($entityId = null) {
        $this->db = Database::getInstance()->getConnection();
        if ($entityId !== null) {
            $this->loadData();
        }
    }

    public function isValid() { return $this->isValid; }
    public function getEntityId() { return $this->entityId; }
    // ... getters and setters
}
```

**Checking user session:**
```php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
```
