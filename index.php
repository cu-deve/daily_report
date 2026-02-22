<?php
/**
 * Daily Report Portal (Single-file PHP app)
 *
 * How to run on XAMPP (PHP 8+):
 * 1) Save this file as: htdocs/daily_report/index.php
 * 2) Start Apache in XAMPP.
 * 3) Open: http://localhost/daily_report/
 * 4) The app auto-creates SQLite database at ./data/app.db on first run.
 * 5) Demo accounts:
 *    - admin / admin123
 *    - manager / manager123
 *    - staff1 / staff123
 *    - staff2 / staff123
 */

declare(strict_types=1);
session_start();
date_default_timezone_set('UTC');

const APP_TITLE = 'Daily Report Portal';

function accounts(): array
{
    return [
        'admin' => ['password' => 'admin123', 'name' => 'Admin User', 'role' => 'Admin', 'team' => null],
        'manager' => ['password' => 'manager123', 'name' => 'Maya Manager', 'role' => 'Manager', 'team' => 'Engineering'],
        'staff1' => ['password' => 'staff123', 'name' => 'Sam Staff', 'role' => 'Staff', 'team' => 'Engineering'],
        'staff2' => ['password' => 'staff123', 'name' => 'Nina Staff', 'role' => 'Staff', 'team' => 'Operations'],
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dir . '/app.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    migrate($pdo);
    seed($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_date TEXT NOT NULL,
        username TEXT NOT NULL,
        staff_name TEXT NOT NULL,
        team_id INTEGER NOT NULL,
        project_id INTEGER NULL,
        project_other TEXT NULL,
        shift TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "Draft",
        plan_today TEXT,
        done_today TEXT,
        blockers TEXT,
        notes TEXT,
        manager_comment TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(team_id) REFERENCES teams(id),
        FOREIGN KEY(project_id) REFERENCES projects(id)
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_reports_user_date ON reports(username, report_date)');
}

function seed(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn() === 0) {
        $teams = ['Engineering', 'Operations', 'Sales'];
        $stmt = $pdo->prepare('INSERT INTO teams(name, is_active) VALUES(?,1)');
        foreach ($teams as $team) {
            $stmt->execute([$team]);
        }
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() === 0) {
        $projects = ['Portal Revamp', 'Client Support', 'Internal Tools', 'Quality Assurance', 'Research'];
        $stmt = $pdo->prepare('INSERT INTO projects(name, is_active) VALUES(?,1)');
        foreach ($projects as $project) {
            $stmt->execute([$project]);
        }
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF validation failed.');
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function base_path(): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $base === '/' ? '' : $base;
}

function url(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');
    return base_path() . ($path === '/' ? '/' : $path);
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function auth_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function auth_login(string $username, string $password): bool
{
    $accounts = accounts();
    if (!isset($accounts[$username]) || !hash_equals($accounts[$username]['password'], $password)) {
        return false;
    }
    $_SESSION['user'] = [
        'username' => $username,
        'name' => $accounts[$username]['name'],
        'role' => $accounts[$username]['role'],
        'team' => $accounts[$username]['team'],
    ];
    session_regenerate_id(true);
    return true;
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function require_auth(): array
{
    $user = auth_user();
    if (!$user) {
        redirect('/login');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $user;
}

function path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return $uri ?: '/';
}

function validate_report(array $input): array
{
    $errors = [];
    $data = [];

    $data['report_date'] = trim($input['report_date'] ?? '');
    $data['team_id'] = (int)($input['team_id'] ?? 0);
    $data['project_id'] = ($input['project_id'] ?? '') === '' ? null : (int)$input['project_id'];
    $data['project_other'] = trim($input['project_other'] ?? '');
    $data['shift'] = trim($input['shift'] ?? 'Morning');
    $data['plan_today'] = trim($input['plan_today'] ?? '');
    $data['done_today'] = trim($input['done_today'] ?? '');
    $data['blockers'] = trim($input['blockers'] ?? '');
    $data['notes'] = trim($input['notes'] ?? '');

    if (!$data['report_date'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['report_date'])) {
        $errors['report_date'] = 'Report date is required.';
    } elseif ($data['report_date'] > date('Y-m-d')) {
        $errors['report_date'] = 'Report date cannot be in the future.';
    }

    if ($data['team_id'] <= 0) {
        $errors['team_id'] = 'Team is required.';
    }

    if (!in_array($data['shift'], ['Morning', 'Afternoon', 'Night'], true)) {
        $errors['shift'] = 'Invalid shift.';
    }

    if ($data['project_id'] === -1 && $data['project_other'] === '') {
        $errors['project_other'] = 'Please provide custom project name.';
    }

    return [$errors, $data];
}

function fetch_teams(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name, is_active FROM teams' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';
    return db()->query($sql)->fetchAll();
}

function fetch_projects(bool $activeOnly = true): array
{
    $sql = 'SELECT id, name, is_active FROM projects' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';
    return db()->query($sql)->fetchAll();
}

function find_report(int $id): ?array
{
    $stmt = db()->prepare('SELECT r.*, t.name as team_name, p.name as project_name
        FROM reports r
        JOIN teams t ON t.id = r.team_id
        LEFT JOIN projects p ON p.id = r.project_id
        WHERE r.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function can_view_report(array $user, array $report): bool
{
    if ($user['role'] === 'Admin') return true;
    if ($user['role'] === 'Manager') return $user['team'] === $report['team_name'];
    return $user['username'] === $report['username'];
}

function can_edit_report(array $user, array $report): bool
{
    return $user['role'] === 'Staff' && $user['username'] === $report['username'] && $report['status'] === 'Draft';
}

function status_badge(string $status): string
{
    $map = [
        'Draft' => 'bg-slate-100 text-slate-700',
        'Submitted' => 'bg-blue-100 text-blue-700',
        'Approved' => 'bg-emerald-100 text-emerald-700',
        'Needs Changes' => 'bg-amber-100 text-amber-700',
    ];
    $class = $map[$status] ?? 'bg-slate-100 text-slate-700';
    return '<span class="px-3 py-1 text-xs font-semibold rounded-full ' . $class . '">' . e($status) . '</span>';
}

function layout_start(string $title): void
{
    $user = auth_user();
    $flash = get_flash();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> - <?= e(APP_TITLE) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{fontFamily:{sans:['Inter','sans-serif']}}}};</script>
</head>
<body class="bg-slate-50 font-sans text-slate-800">
<div class="min-h-screen lg:flex">
  <?php if ($user): ?>
  <aside id="drawer" class="fixed z-40 inset-y-0 left-0 w-72 bg-white/90 backdrop-blur border-r border-slate-200 p-6 transform -translate-x-full lg:translate-x-0 transition-transform">
    <div class="text-xl font-extrabold mb-6"><?= e(APP_TITLE) ?></div>
    <nav class="space-y-2 text-sm">
      <a class="block px-4 py-2 rounded-xl hover:bg-slate-100" href="<?= e(url('/dashboard')) ?>">Dashboard</a>
      <a class="block px-4 py-2 rounded-xl hover:bg-slate-100" href="<?= e(url('/reports')) ?>">Reports</a>
      <?php if ($user['role'] === 'Staff'): ?><a class="block px-4 py-2 rounded-xl hover:bg-slate-100" href="<?= e(url('/reports/new')) ?>">New Report</a><?php endif; ?>
      <?php if ($user['role'] === 'Admin'): ?>
        <a class="block px-4 py-2 rounded-xl hover:bg-slate-100" href="<?= e(url('/settings/teams')) ?>">Teams</a>
        <a class="block px-4 py-2 rounded-xl hover:bg-slate-100" href="<?= e(url('/settings/projects')) ?>">Projects</a>
      <?php endif; ?>
    </nav>
    <div class="absolute bottom-6 left-6 right-6">
      <div class="p-3 rounded-xl bg-slate-100 text-xs mb-3">Signed in as <strong><?= e($user['name']) ?></strong><br><?= e($user['role']) ?></div>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_input() ?><button class="w-full rounded-xl bg-slate-900 text-white py-2">Logout</button></form>
    </div>
  </aside>
  <?php endif; ?>

  <main class="flex-1 lg:ml-72">
    <?php if ($user): ?>
      <header class="sticky top-0 z-30 bg-white/80 backdrop-blur border-b border-slate-200 px-4 py-3 lg:hidden flex justify-between items-center">
        <button onclick="document.getElementById('drawer').classList.toggle('-translate-x-full')" class="p-2 rounded-lg bg-slate-100">☰</button>
        <div class="font-bold"><?= e(APP_TITLE) ?></div>
      </header>
    <?php endif; ?>
    <div class="p-4 lg:p-8">
      <?php if ($flash): ?>
        <div id="toast" class="mb-4 rounded-2xl shadow-lg px-4 py-3 <?= $flash['type'] === 'success' ? 'bg-emerald-600' : 'bg-rose-600' ?> text-white">
          <?= e($flash['message']) ?>
        </div>
        <script>setTimeout(()=>document.getElementById('toast')?.remove(),3200);</script>
      <?php endif; ?>
    <?php
}

function layout_end(): void
{
    ?>
    </div>
  </main>
</div>
<script>
function confirmDelete(formId){
  const modal=document.getElementById('delete-modal');
  modal.classList.remove('hidden');
  modal.dataset.form=formId;
}
function closeDelete(){document.getElementById('delete-modal')?.classList.add('hidden');}
function proceedDelete(){const id=document.getElementById('delete-modal').dataset.form;document.getElementById(id)?.submit();}
</script>
<div id="delete-modal" class="hidden fixed inset-0 bg-slate-900/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-2xl">
    <h3 class="font-bold text-lg mb-2">Confirm Delete</h3>
    <p class="text-sm text-slate-600 mb-4">This action cannot be undone.</p>
    <div class="flex justify-end gap-2">
      <button onclick="closeDelete()" class="px-4 py-2 rounded-xl bg-slate-100">Cancel</button>
      <button onclick="proceedDelete()" class="px-4 py-2 rounded-xl bg-rose-600 text-white">Delete</button>
    </div>
  </div>
</div>
</body></html><?php
}

function view_login(array $errors = []): void
{
    layout_start('Login'); ?>
    <div class="max-w-md mx-auto mt-16 bg-white rounded-3xl shadow-xl border border-slate-200 p-8">
      <h1 class="text-2xl font-extrabold mb-1">Welcome back</h1>
      <p class="text-sm text-slate-500 mb-6">Sign in to continue.</p>
      <form method="post" action="<?= e(url('/login')) ?>" class="space-y-4">
        <?= csrf_input() ?>
        <div><label class="text-sm">Username</label><input name="username" class="w-full mt-1 rounded-xl border-slate-300" required></div>
        <div><label class="text-sm">Password</label><input type="password" name="password" class="w-full mt-1 rounded-xl border-slate-300" required></div>
        <?php if ($errors): ?><p class="text-sm text-rose-600"><?= e(implode(' ', $errors)) ?></p><?php endif; ?>
        <button class="w-full bg-slate-900 text-white rounded-xl py-2 font-semibold">Login</button>
      </form>
    </div>
    <?php layout_end();
}

function view_dashboard(array $user): void
{
    $pdo = db();
    $today = date('Y-m-d');
    $where = '1=1';
    $params = [];
    if ($user['role'] === 'Staff') { $where .= ' AND username = ?'; $params[] = $user['username']; }
    if ($user['role'] === 'Manager') { $where .= ' AND t.name = ?'; $params[] = $user['team']; }
    $cards = [];
    foreach ([
        'Reports Today' => 'report_date = ? ',
        'Submitted Today' => 'report_date = ? AND status = "Submitted"',
        'Needs Changes' => 'status = "Needs Changes"',
        'Approved' => 'status = "Approved"',
    ] as $label => $condition) {
        $sql = "SELECT COUNT(*) FROM reports r JOIN teams t ON t.id=r.team_id WHERE $where AND $condition";
        $p = $params;
        if (str_contains($condition, 'report_date = ?')) $p[] = $today;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        $cards[$label] = (int)$stmt->fetchColumn();
    }

    layout_start('Dashboard'); ?>
    <h1 class="text-2xl font-extrabold mb-6">Dashboard</h1>
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
      <?php foreach ($cards as $k => $v): ?>
      <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
        <p class="text-sm text-slate-500"><?= e($k) ?></p>
        <p class="text-3xl font-extrabold mt-2"><?= $v ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-6"><a class="inline-flex px-4 py-2 rounded-xl bg-slate-900 text-white" href="<?= e(url('/reports')) ?>">Open reports</a></div>
    <?php layout_end();
}

function build_report_filters(array $user): array
{
    $f = [
        'from' => trim($_GET['from'] ?? ''),
        'to' => trim($_GET['to'] ?? ''),
        'status' => trim($_GET['status'] ?? ''),
        'team_id' => trim($_GET['team_id'] ?? ''),
        'project_id' => trim($_GET['project_id'] ?? ''),
        'staff' => trim($_GET['staff'] ?? ''),
    ];
    $where = ['1=1']; $params = [];
    if ($user['role'] === 'Staff') { $where[] = 'r.username = ?'; $params[] = $user['username']; }
    if ($user['role'] === 'Manager') { $where[] = 't.name = ?'; $params[] = $user['team']; }
    if ($f['from']) { $where[] = 'r.report_date >= ?'; $params[] = $f['from']; }
    if ($f['to']) { $where[] = 'r.report_date <= ?'; $params[] = $f['to']; }
    if ($f['status']) { $where[] = 'r.status = ?'; $params[] = $f['status']; }
    if ($f['team_id']) { $where[] = 'r.team_id = ?'; $params[] = (int)$f['team_id']; }
    if ($f['project_id']) {
        if ($f['project_id'] === 'other') $where[] = '(r.project_id IS NULL AND COALESCE(r.project_other,"") <> "")';
        else { $where[] = 'r.project_id = ?'; $params[] = (int)$f['project_id']; }
    }
    if ($f['staff']) { $where[] = '(r.staff_name LIKE ? OR r.username LIKE ?)'; $params[] = '%' . $f['staff'] . '%'; $params[] = '%' . $f['staff'] . '%'; }
    return [$f, $where, $params];
}

function view_reports(array $user): void
{
    [$f, $where, $params] = build_report_filters($user);
    $pdo = db();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 10;
    $offset = ($page - 1) * $per;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reports r JOIN teams t ON t.id=r.team_id LEFT JOIN projects p ON p.id=r.project_id WHERE ' . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT r.*, t.name team_name, p.name project_name FROM reports r JOIN teams t ON t.id=r.team_id LEFT JOIN projects p ON p.id=r.project_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.report_date DESC, r.updated_at DESC LIMIT ? OFFSET ?');
    $stmt->execute(array_merge($params, [$per, $offset]));
    $rows = $stmt->fetchAll();

    layout_start('Reports'); ?>
    <div class="flex justify-between items-center mb-4"><h1 class="text-2xl font-extrabold">Reports</h1><?php if ($user['role']==='Staff'): ?><a href="<?= e(url('/reports/new')) ?>" class="px-4 py-2 rounded-xl bg-slate-900 text-white">+ New</a><?php endif; ?></div>

    <form class="bg-white rounded-2xl border border-slate-200 p-4 mb-4 grid md:grid-cols-3 lg:grid-cols-6 gap-3">
      <input type="date" name="from" value="<?= e($f['from']) ?>" class="rounded-xl border-slate-300">
      <input type="date" name="to" value="<?= e($f['to']) ?>" class="rounded-xl border-slate-300">
      <select name="status" class="rounded-xl border-slate-300"><option value="">All Status</option><?php foreach(['Draft','Submitted','Approved','Needs Changes'] as $s): ?><option <?= $f['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
      <select name="team_id" class="rounded-xl border-slate-300"><option value="">All Teams</option><?php foreach(fetch_teams(false) as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $f['team_id']==(string)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
      <select name="project_id" class="rounded-xl border-slate-300"><option value="">All Projects</option><?php foreach(fetch_projects(false) as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $f['project_id']==(string)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?><option value="other" <?= $f['project_id']==='other'?'selected':'' ?>>Other</option></select>
      <input name="staff" placeholder="Staff search" value="<?= e($f['staff']) ?>" class="rounded-xl border-slate-300">
      <div class="md:col-span-3 lg:col-span-6 flex gap-2">
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white">Filter</button>
        <a class="px-4 py-2 rounded-xl bg-slate-100" href="<?= e(url('/reports')) ?>">Reset</a>
        <a class="px-4 py-2 rounded-xl bg-emerald-600 text-white" href="<?= e(url('/export/csv')) ?>?<?= e(http_build_query($f)) ?>">Export CSV</a>
      </div>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-100"><tr><th class="p-3 text-left">Date</th><th class="p-3 text-left">Staff</th><th class="p-3">Team</th><th class="p-3">Project</th><th class="p-3">Status</th><th class="p-3"></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="p-8 text-center text-slate-500">No reports found.</td></tr><?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr class="border-t"><td class="p-3"><?= e($r['report_date']) ?></td><td class="p-3"><?= e($r['staff_name']) ?></td><td class="p-3"><?= e($r['team_name']) ?></td><td class="p-3"><?= e($r['project_name'] ?: $r['project_other'] ?: '-') ?></td><td class="p-3"><?= status_badge($r['status']) ?></td>
            <td class="p-3 text-right"><a class="text-slate-700 hover:underline" href="<?= e(url('/reports/view')) ?>?id=<?= (int)$r['id'] ?>">View</a></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
      <div class="mt-4 flex gap-2 flex-wrap">
      <?php for ($i=1;$i<=$pages;$i++): $q = array_merge($f,['page'=>$i]); ?>
        <a class="px-3 py-1 rounded-lg <?= $i===$page?'bg-slate-900 text-white':'bg-white border' ?>" href="<?= e(url('/reports')) ?>?<?= e(http_build_query($q)) ?>"><?= $i ?></a>
      <?php endfor; ?>
      </div>
    <?php endif; ?>
    <?php layout_end();
}

function view_report_form(array $user, string $mode, ?array $report = null, array $errors = [], array $old = []): void
{
    $teams = fetch_teams(true);
    $projects = fetch_projects(true);
    $data = $old ?: [
        'report_date' => $report['report_date'] ?? date('Y-m-d'),
        'team_id' => $report['team_id'] ?? '',
        'project_id' => $report['project_id'] ?? '',
        'project_other' => $report['project_other'] ?? '',
        'shift' => $report['shift'] ?? 'Morning',
        'plan_today' => $report['plan_today'] ?? '',
        'done_today' => $report['done_today'] ?? '',
        'blockers' => $report['blockers'] ?? '',
        'notes' => $report['notes'] ?? '',
    ];

    layout_start($mode === 'new' ? 'New Report' : 'Edit Report'); ?>
    <div class="max-w-4xl mx-auto bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
    <h1 class="text-2xl font-extrabold mb-5"><?= $mode === 'new' ? 'Create Daily Report' : 'Edit Daily Report' ?></h1>
    <form method="post" class="grid md:grid-cols-2 gap-4">
      <?= csrf_input() ?>
      <div><label class="text-sm">Date *</label><input type="date" name="report_date" value="<?= e((string)$data['report_date']) ?>" class="w-full rounded-xl border-slate-300"></div>
      <div><label class="text-sm">Team *</label><select name="team_id" class="w-full rounded-xl border-slate-300"><option value="">Select</option><?php foreach($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)$data['team_id']===(string)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="text-sm">Project</label><select name="project_id" id="project_id" class="w-full rounded-xl border-slate-300" onchange="document.getElementById('project_other_wrap').classList.toggle('hidden', this.value !== '-1')"><option value="">None</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (string)$data['project_id']===(string)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?><option value="-1" <?= (string)$data['project_id']==='-1'?'selected':'' ?>>Other</option></select></div>
      <div id="project_other_wrap" class="<?= (string)$data['project_id']==='-1'?'':'hidden' ?>"><label class="text-sm">Other Project</label><input name="project_other" value="<?= e((string)$data['project_other']) ?>" class="w-full rounded-xl border-slate-300"></div>
      <div><label class="text-sm">Shift</label><select name="shift" class="w-full rounded-xl border-slate-300"><?php foreach(['Morning','Afternoon','Night'] as $s): ?><option <?= $data['shift']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
      <div class="md:col-span-2"><label class="text-sm">Plan Today</label><textarea name="plan_today" class="w-full rounded-xl border-slate-300" rows="3"><?= e((string)$data['plan_today']) ?></textarea></div>
      <div class="md:col-span-2"><label class="text-sm">Done Today</label><textarea name="done_today" class="w-full rounded-xl border-slate-300" rows="3"><?= e((string)$data['done_today']) ?></textarea></div>
      <div><label class="text-sm">Blockers</label><textarea name="blockers" class="w-full rounded-xl border-slate-300" rows="3"><?= e((string)$data['blockers']) ?></textarea></div>
      <div><label class="text-sm">Notes</label><textarea name="notes" class="w-full rounded-xl border-slate-300" rows="3"><?= e((string)$data['notes']) ?></textarea></div>
      <?php if ($errors): ?><div class="md:col-span-2 text-rose-600 text-sm"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
      <div class="md:col-span-2 flex gap-2"><button class="px-5 py-2 rounded-xl bg-slate-900 text-white">Save Draft</button><a href="<?= e(url('/reports')) ?>" class="px-5 py-2 rounded-xl bg-slate-100">Cancel</a></div>
    </form>
    </div>
    <?php layout_end();
}

function view_report_detail(array $user, array $r): void
{
    layout_start('View Report'); ?>
    <div class="max-w-4xl mx-auto space-y-4">
      <div class="bg-white rounded-3xl border p-6">
        <div class="flex justify-between items-start"><div><h1 class="text-2xl font-extrabold mb-1"><?= e($r['staff_name']) ?> - <?= e($r['report_date']) ?></h1><p class="text-sm text-slate-500"><?= e($r['team_name']) ?> / <?= e($r['project_name'] ?: $r['project_other'] ?: '-') ?> / <?= e($r['shift']) ?></p></div><?= status_badge($r['status']) ?></div>
        <dl class="mt-4 grid md:grid-cols-2 gap-4 text-sm">
          <div><dt class="font-semibold">Plan Today</dt><dd class="text-slate-600 whitespace-pre-wrap"><?= e($r['plan_today']) ?></dd></div>
          <div><dt class="font-semibold">Done Today</dt><dd class="text-slate-600 whitespace-pre-wrap"><?= e($r['done_today']) ?></dd></div>
          <div><dt class="font-semibold">Blockers</dt><dd class="text-slate-600 whitespace-pre-wrap"><?= e($r['blockers']) ?></dd></div>
          <div><dt class="font-semibold">Notes</dt><dd class="text-slate-600 whitespace-pre-wrap"><?= e($r['notes']) ?></dd></div>
          <div class="md:col-span-2"><dt class="font-semibold">Manager Comment</dt><dd class="text-slate-600 whitespace-pre-wrap"><?= e($r['manager_comment']) ?></dd></div>
        </dl>
      </div>
      <div class="bg-white rounded-2xl border p-4 flex flex-wrap gap-2">
        <?php if (can_edit_report($user, $r)): ?><a class="px-4 py-2 rounded-xl bg-slate-900 text-white" href="<?= e(url('/reports/edit')) ?>?id=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?>
        <?php if ($user['role'] === 'Staff' && $r['status'] === 'Draft' && $r['username'] === $user['username']): ?>
          <form method="post" action="<?= e(url('/reports/submit')) ?>?id=<?= (int)$r['id'] ?>"><?= csrf_input() ?><button class="px-4 py-2 rounded-xl bg-blue-600 text-white">Submit</button></form>
        <?php endif; ?>
        <?php if (in_array($user['role'], ['Manager','Admin'], true)): ?>
          <form method="post" action="<?= e(url('/reports/status')) ?>?id=<?= (int)$r['id'] ?>" class="flex gap-2 items-center"><?= csrf_input() ?>
            <select name="status" class="rounded-xl border-slate-300"><?php foreach(['Submitted','Approved','Needs Changes'] as $s): ?><option <?= $r['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
            <input name="manager_comment" value="<?= e($r['manager_comment']) ?>" placeholder="Manager comment" class="rounded-xl border-slate-300">
            <button class="px-4 py-2 rounded-xl bg-emerald-600 text-white">Update</button>
          </form>
        <?php endif; ?>
        <?php if ($user['role']==='Admin' || ($user['role']==='Staff' && $r['status']==='Draft' && $r['username']===$user['username'])): ?>
          <form id="del-<?= (int)$r['id'] ?>" method="post" action="<?= e(url('/reports/delete')) ?>?id=<?= (int)$r['id'] ?>"><?= csrf_input() ?></form>
          <button onclick="confirmDelete('del-<?= (int)$r['id'] ?>')" class="px-4 py-2 rounded-xl bg-rose-600 text-white">Delete</button>
        <?php endif; ?>
      </div>
    </div>
    <?php layout_end();
}

function view_settings(string $kind, array $rows): void
{
    layout_start(ucfirst($kind)); ?>
    <div class="max-w-3xl mx-auto bg-white border rounded-3xl p-6">
      <h1 class="text-2xl font-extrabold mb-4">Manage <?= e(ucfirst($kind)) ?></h1>
      <form method="post" class="flex gap-2 mb-4"><?= csrf_input() ?><input name="name" class="flex-1 rounded-xl border-slate-300" placeholder="New <?= e($kind) ?> name" required><button class="px-4 py-2 rounded-xl bg-slate-900 text-white">Add</button></form>
      <div class="space-y-2">
      <?php foreach ($rows as $r): ?>
        <div class="flex items-center justify-between border rounded-xl p-3">
          <span><?= e($r['name']) ?></span>
          <form method="post" class="flex items-center gap-2">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="toggle" value="1">
            <button class="px-3 py-1 rounded-lg <?= (int)$r['is_active']===1?'bg-amber-100':'bg-emerald-100' ?>"><?= (int)$r['is_active']===1?'Deactivate':'Activate' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php layout_end();
}

function router(): void
{
    $p = path();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($p === '/' || $p === '') {
        redirect(auth_user() ? '/dashboard' : '/login');
    }

    if ($p === '/login' && $method === 'GET') {
        if (auth_user()) redirect('/dashboard');
        view_login(); return;
    }

    if ($p === '/login' && $method === 'POST') {
        csrf_verify();
        $u = trim($_POST['username'] ?? '');
        $pw = $_POST['password'] ?? '';
        if (auth_login($u, $pw)) { flash('success', 'Welcome back!'); redirect('/dashboard'); }
        view_login(['Invalid credentials.']); return;
    }

    if ($p === '/logout' && $method === 'POST') {
        csrf_verify(); auth_logout(); session_start(); flash('success', 'Logged out.'); redirect('/login');
    }

    if ($p === '/dashboard') { view_dashboard(require_auth()); return; }
    if ($p === '/reports') { view_reports(require_auth()); return; }

    if ($p === '/reports/new') {
        $user = require_role(['Staff']);
        if ($method === 'GET') { view_report_form($user, 'new'); return; }
        csrf_verify();
        [$errors, $data] = validate_report($_POST);
        if (!$errors) {
            $check = db()->prepare('SELECT COUNT(*) FROM reports WHERE username=? AND report_date=?');
            $check->execute([$user['username'], $data['report_date']]);
            if ((int)$check->fetchColumn() > 0) $errors['dup'] = 'Duplicate report for this date is not allowed.';
        }
        if ($errors) { view_report_form($user, 'new', null, $errors, $_POST); return; }
        $stmt = db()->prepare('INSERT INTO reports(report_date, username, staff_name, team_id, project_id, project_other, shift, status, plan_today, done_today, blockers, notes, manager_comment, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $projectId = $data['project_id'] === -1 ? null : $data['project_id'];
        $projectOther = $data['project_id'] === -1 ? $data['project_other'] : null;
        $stmt->execute([$data['report_date'], $user['username'], $user['name'], $data['team_id'], $projectId, $projectOther, $data['shift'], 'Draft', $data['plan_today'], $data['done_today'], $data['blockers'], $data['notes'], '', now(), now()]);
        flash('success', 'Draft report created.'); redirect('/reports');
    }

    if ($p === '/reports/edit') {
        $user = require_role(['Staff']);
        $id = (int)($_GET['id'] ?? 0);
        $report = find_report($id);
        if (!$report || !can_edit_report($user, $report)) { http_response_code(403); exit('Cannot edit this report.'); }
        if ($method === 'GET') { view_report_form($user, 'edit', $report); return; }
        csrf_verify();
        [$errors, $data] = validate_report($_POST);
        if ($data['report_date'] !== $report['report_date']) {
            $dup = db()->prepare('SELECT COUNT(*) FROM reports WHERE username=? AND report_date=? AND id<>?');
            $dup->execute([$user['username'], $data['report_date'], $id]);
            if ((int)$dup->fetchColumn() > 0) $errors['dup'] = 'Duplicate report date for your account.';
        }
        if ($errors) { view_report_form($user, 'edit', $report, $errors, $_POST); return; }
        $stmt = db()->prepare('UPDATE reports SET report_date=?, team_id=?, project_id=?, project_other=?, shift=?, plan_today=?, done_today=?, blockers=?, notes=?, updated_at=? WHERE id=?');
        $projectId = $data['project_id'] === -1 ? null : $data['project_id'];
        $projectOther = $data['project_id'] === -1 ? $data['project_other'] : null;
        $stmt->execute([$data['report_date'], $data['team_id'], $projectId, $projectOther, $data['shift'], $data['plan_today'], $data['done_today'], $data['blockers'], $data['notes'], now(), $id]);
        flash('success', 'Report updated.'); redirect('/reports/view?id=' . $id);
    }

    if ($p === '/reports/view') {
        $user = require_auth();
        $id = (int)($_GET['id'] ?? 0);
        $r = find_report($id);
        if (!$r || !can_view_report($user, $r)) { http_response_code(404); exit('Report not found.'); }
        view_report_detail($user, $r); return;
    }

    if ($p === '/reports/submit' && $method === 'POST') {
        $user = require_role(['Staff']); csrf_verify();
        $id = (int)($_GET['id'] ?? 0); $r = find_report($id);
        if (!$r || !can_edit_report($user, $r)) { http_response_code(403); exit('Cannot submit.'); }
        $stmt = db()->prepare('UPDATE reports SET status="Submitted", updated_at=? WHERE id=?');
        $stmt->execute([now(), $id]); flash('success', 'Report submitted.'); redirect('/reports/view?id=' . $id);
    }

    if ($p === '/reports/status' && $method === 'POST') {
        $user = require_role(['Manager','Admin']); csrf_verify();
        $id = (int)($_GET['id'] ?? 0); $r = find_report($id);
        if (!$r || !can_view_report($user, $r)) { http_response_code(403); exit('Cannot update status.'); }
        $status = trim($_POST['status'] ?? 'Submitted');
        if (!in_array($status, ['Submitted','Approved','Needs Changes'], true)) { http_response_code(422); exit('Invalid status.'); }
        $comment = trim($_POST['manager_comment'] ?? '');
        $stmt = db()->prepare('UPDATE reports SET status=?, manager_comment=?, updated_at=? WHERE id=?');
        $stmt->execute([$status, $comment, now(), $id]); flash('success', 'Status updated.'); redirect('/reports/view?id=' . $id);
    }

    if ($p === '/reports/delete' && $method === 'POST') {
        $user = require_auth(); csrf_verify();
        $id = (int)($_GET['id'] ?? 0); $r = find_report($id);
        if (!$r) { http_response_code(404); exit('Not found.'); }
        $can = $user['role'] === 'Admin' || ($user['role'] === 'Staff' && $r['username']===$user['username'] && $r['status']==='Draft');
        if (!$can) { http_response_code(403); exit('Forbidden'); }
        $stmt = db()->prepare('DELETE FROM reports WHERE id=?'); $stmt->execute([$id]);
        flash('success', 'Report deleted.'); redirect('/reports');
    }

    if ($p === '/settings/teams') {
        require_role(['Admin']);
        if ($method === 'POST') {
            csrf_verify();
            if (isset($_POST['toggle'], $_POST['id'])) {
                $id = (int)$_POST['id'];
                db()->prepare('UPDATE teams SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?')->execute([$id]);
            } else {
                $name = trim($_POST['name'] ?? '');
                if ($name !== '') db()->prepare('INSERT OR IGNORE INTO teams(name,is_active) VALUES(?,1)')->execute([$name]);
            }
            flash('success', 'Teams updated.'); redirect('/settings/teams');
        }
        view_settings('teams', fetch_teams(false)); return;
    }

    if ($p === '/settings/projects') {
        require_role(['Admin']);
        if ($method === 'POST') {
            csrf_verify();
            if (isset($_POST['toggle'], $_POST['id'])) {
                $id = (int)$_POST['id'];
                db()->prepare('UPDATE projects SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?')->execute([$id]);
            } else {
                $name = trim($_POST['name'] ?? '');
                if ($name !== '') db()->prepare('INSERT OR IGNORE INTO projects(name,is_active) VALUES(?,1)')->execute([$name]);
            }
            flash('success', 'Projects updated.'); redirect('/settings/projects');
        }
        view_settings('projects', fetch_projects(false)); return;
    }

    if ($p === '/export/csv') {
        $user = require_auth();
        [$f, $where, $params] = build_report_filters($user);
        $stmt = db()->prepare('SELECT r.report_date, r.staff_name, t.name team_name, COALESCE(p.name,r.project_other,"") project_name, r.shift, r.status, r.plan_today, r.done_today, r.blockers, r.notes, r.manager_comment, r.updated_at FROM reports r JOIN teams t ON t.id=r.team_id LEFT JOIN projects p ON p.id=r.project_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.report_date DESC, r.updated_at DESC');
        $stmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reports_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['date','staff','team','project','shift','status','plan_today','done_today','blockers','notes','manager_comment','updated_at']);
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($out, $row); }
        fclose($out); exit;
    }

    http_response_code(404);
    echo 'Not Found';
}

router();
