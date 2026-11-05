<?php
declare(strict_types=1);

/**
 * To-do List (COMP016 Lab Activity 4)
 *
 * Tasks are stored in $_SESSION: no database, no frameworks.
 *
 * File layout
 *   1. Configuration and bootstrap
 *   2. Helpers
 *   3. Actions (add, update, toggle, delete, clear completed)
 *   4. Request handling (POST -> action -> redirect -> GET)
 *   5. View data
 *   6. Template
 */

/* ---------------------------------------------------------------------------
 * 1. Configuration and bootstrap
 * ------------------------------------------------------------------------ */

const MAX_TITLE_LENGTH = 100;
const MAX_TASKS        = 100;
const PRIORITIES       = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$_SESSION['tasks']   ??= [];
$_SESSION['next_id'] ??= 1;
$_SESSION['csrf']    ??= bin2hex(random_bytes(32));

/* ---------------------------------------------------------------------------
 * 2. Helpers
 * ------------------------------------------------------------------------ */

/** Escape a value for safe output inside HTML (prevents XSS). */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Read a string from $_POST, ignoring tampered values such as arrays. */
function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

/** Trim the value and collapse repeated whitespace into single spaces. */
function normalize(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/** Store a one-time message that is shown after the redirect. */
function flash(string $type, string $message, ?int $taskId = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'task_id' => $taskId];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function find_task_index(int $id): ?int
{
    foreach ($_SESSION['tasks'] as $index => $task) {
        if ($task['id'] === $id) {
            return $index;
        }
    }

    return null;
}

/**
 * Read and validate the title and priority submitted by the add/edit forms.
 *
 * @return array{0: string, 1: string, 2: ?string} [title, priority, error]
 */
function read_task_input(): array
{
    $title    = normalize(post_string('title'));
    $priority = post_string('priority', 'medium');
    $error    = null;

    if (!array_key_exists($priority, PRIORITIES)) {
        $priority = 'medium';
    }

    if ($title === '') {
        $error = 'Enter a task title.';
    } elseif (mb_strlen($title) > MAX_TITLE_LENGTH) {
        $error = 'Keep the title to ' . MAX_TITLE_LENGTH . ' characters or fewer.';
    }

    return [$title, $priority, $error];
}

/* ---------------------------------------------------------------------------
 * 3. Actions
 * ------------------------------------------------------------------------ */

function add_task(): void
{
    [$title, $priority, $error] = read_task_input();

    if ($error === null && count($_SESSION['tasks']) >= MAX_TASKS) {
        $error = 'You can keep up to ' . MAX_TASKS . ' tasks. Delete one to add another.';
    }

    if ($error !== null) {
        $_SESSION['old'] = ['title' => post_string('title'), 'priority' => $priority];
        flash('error', $error);
        return;
    }

    $id = $_SESSION['next_id']++;
    $_SESSION['tasks'][] = [
        'id'       => $id,
        'title'    => $title,
        'priority' => $priority,
        'done'     => false,
        'created'  => time(),
    ];
    flash('success', 'Task added.', $id);
}

function update_task(int $id): void
{
    $index = find_task_index($id);
    if ($index === null) {
        flash('error', 'That task no longer exists.');
        return;
    }

    [$title, $priority, $error] = read_task_input();
    if ($error !== null) {
        flash('error', $error);
        return;
    }

    $_SESSION['tasks'][$index]['title']    = $title;
    $_SESSION['tasks'][$index]['priority'] = $priority;
    flash('success', 'Task updated.', $id);
}

function toggle_task(int $id): void
{
    $index = find_task_index($id);
    if ($index !== null) {
        $_SESSION['tasks'][$index]['done'] = !$_SESSION['tasks'][$index]['done'];
    }
}

function delete_task(int $id): void
{
    $index = find_task_index($id);
    if ($index === null) {
        flash('error', 'That task no longer exists.');
        return;
    }

    array_splice($_SESSION['tasks'], $index, 1);
    flash('success', 'Task deleted.');
}

function clear_done(): void
{
    $before = count($_SESSION['tasks']);
    $_SESSION['tasks'] = array_values(
        array_filter($_SESSION['tasks'], fn (array $task): bool => !$task['done'])
    );
    $removed = $before - count($_SESSION['tasks']);

    flash('success', $removed === 1 ? 'Cleared 1 completed task.' : "Cleared {$removed} completed tasks.");
}

/* ---------------------------------------------------------------------------
 * 4. Request handling
 *
 * Every state change arrives as a POST. The script runs the action, then
 * redirects (Post/Redirect/Get) so a page refresh never re-submits the form.
 * ------------------------------------------------------------------------ */

function handle_post(): void
{
    if (!hash_equals($_SESSION['csrf'], post_string('csrf'))) {
        flash('error', 'Your session expired. Please try again.');
        return;
    }

    $id = (int) post_string('id', '0');

    match (post_string('action')) {
        'add'        => add_task(),
        'update'     => update_task($id),
        'toggle'     => toggle_task($id),
        'delete'     => delete_task($id),
        'clear_done' => clear_done(),
        default      => flash('error', 'Unknown action.'),
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post();
    header('Location: ' . (strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/'), true, 303);
    exit;
}

/* ---------------------------------------------------------------------------
 * 5. View data
 * ------------------------------------------------------------------------ */

$flash = $_SESSION['flash'] ?? null;
$old   = $_SESSION['old'] ?? ['title' => '', 'priority' => 'medium'];
unset($_SESSION['flash'], $_SESSION['old']);

$tasks   = array_reverse($_SESSION['tasks']); // newest first
$total   = count($tasks);
$done    = count(array_filter($tasks, fn (array $task): bool => $task['done']));
$active  = $total - $done;
$theme   = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';

?>
<!DOCTYPE html>
<html lang="en"<?= $theme !== '' ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>To-do list</title>
    <link rel="stylesheet" href="style.css">
    <script src="script.js" defer></script>
</head>
<body>
<a class="skip-link" href="#taskList">Skip to tasks</a>

<div class="app">
    <header class="app-header">
        <div>
            <h1 class="app-title">To-do list</h1>
            <p class="app-subtitle">Stay on top of what needs to get done.</p>
        </div>
        <button type="button" class="btn" id="themeToggle" hidden>Dark mode</button>
    </header>

    <main>
        <section class="card" aria-labelledby="progressTitle">
            <div class="progress-head">
                <h2 class="card-title" id="progressTitle">Progress</h2>
                <p class="progress-summary"><?= $done ?> of <?= $total ?> done</p>
            </div>
            <progress class="progress" max="<?= max($total, 1) ?>" value="<?= $done ?>" aria-labelledby="progressTitle"></progress>
        </section>

        <section class="card" aria-labelledby="addTitle">
            <h2 class="card-title" id="addTitle">New task</h2>
            <form method="post" action="" class="add-form" id="addForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">

                <div class="add-form-title">
                    <label class="visually-hidden" for="titleInput">Task title</label>
                    <input class="input" type="text" name="title" id="titleInput"
                           placeholder="What needs to get done?" maxlength="<?= MAX_TITLE_LENGTH ?>"
                           autocomplete="off" value="<?= e($old['title']) ?>"<?= $flash && $flash['type'] === 'error' ? ' autofocus' : '' ?>>
                    <span class="counter" id="counter" aria-hidden="true">0/<?= MAX_TITLE_LENGTH ?></span>
                </div>

                <label class="visually-hidden" for="prioritySelect">Priority</label>
                <select class="input" name="priority" id="prioritySelect">
                    <?php foreach (PRIORITIES as $value => $label): ?>
                        <option value="<?= $value ?>"<?= $value === $old['priority'] ? ' selected' : '' ?>><?= $label ?> priority</option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary">Add task</button>
            </form>
        </section>

        <section class="card" aria-labelledby="tasksTitle">
            <div class="list-head">
                <h2 class="card-title" id="tasksTitle">Tasks</h2>
                <?php if ($done > 0): ?>
                    <form method="post" action="" data-confirm="Clear <?= $done ?> completed <?= $done === 1 ? 'task' : 'tasks' ?>? This can't be undone." data-confirm-label="Clear completed">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_done">
                        <button type="submit" class="btn btn-small">Clear completed</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="toolbar">
                <div class="filters" role="group" aria-label="Filter tasks">
                    <button type="button" class="filter is-active" data-filter="all" aria-pressed="true">All <span class="filter-count"><?= $total ?></span></button>
                    <button type="button" class="filter" data-filter="active" aria-pressed="false">Active <span class="filter-count"><?= $active ?></span></button>
                    <button type="button" class="filter" data-filter="done" aria-pressed="false">Done <span class="filter-count"><?= $done ?></span></button>
                </div>
                <div class="search">
                    <label class="visually-hidden" for="searchInput">Search tasks</label>
                    <input class="input" type="search" id="searchInput" placeholder="Search tasks" autocomplete="off">
                </div>
            </div>

            <ul class="task-list" id="taskList">
                <?php foreach ($tasks as $task): ?>
                    <?php
                    $isNew = $flash && $flash['type'] === 'success' && $flash['task_id'] === $task['id'];
                    $classes = 'task task-' . $task['priority']
                        . ($task['done'] ? ' is-done' : '')
                        . ($isNew ? ' is-new' : '');
                    ?>
                    <li class="<?= $classes ?>" data-done="<?= $task['done'] ? 'true' : 'false' ?>" data-title="<?= e(mb_strtolower($task['title'])) ?>">
                        <form method="post" action="" class="task-toggle">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $task['id'] ?>">
                            <button type="submit" class="check"
                                    aria-label="<?= $task['done'] ? 'Mark as not done: ' : 'Mark as done: ' ?><?= e($task['title']) ?>"></button>
                        </form>

                        <div class="task-body">
                            <div class="task-view">
                                <p class="task-title"><?= e($task['title']) ?></p>
                                <p class="task-meta">
                                    <span class="chip"><?= e(PRIORITIES[$task['priority']]) ?> priority</span>
                                    <time datetime="<?= date('c', $task['created']) ?>">Added <?= date('M j, g:i A', $task['created']) ?></time>
                                </p>
                            </div>

                            <form method="post" action="" class="task-edit" hidden>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                <input class="input" type="text" name="title" value="<?= e($task['title']) ?>"
                                       maxlength="<?= MAX_TITLE_LENGTH ?>" autocomplete="off" aria-label="Task title">
                                <select class="input" name="priority" aria-label="Priority">
                                    <?php foreach (PRIORITIES as $value => $label): ?>
                                        <option value="<?= $value ?>"<?= $value === $task['priority'] ? ' selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-small">Save</button>
                                <button type="button" class="btn btn-small" data-action="cancel-edit">Cancel</button>
                            </form>
                        </div>

                        <div class="task-actions">
                            <button type="button" class="btn btn-small" data-action="edit">Edit</button>
                            <form method="post" action="" data-confirm="Delete &ldquo;<?= e($task['title']) ?>&rdquo;? This can't be undone." data-confirm-label="Delete">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="empty" id="emptyState"<?= $total > 0 ? ' hidden' : '' ?>>No tasks yet. Add your first one above.</p>
            <p class="empty" id="noMatch" hidden>No tasks match this view.</p>
        </section>
    </main>

    <footer class="app-footer">
        <p>COMP016 Lab Activity 4. Tasks are stored in your PHP session.</p>
        <p>Shortcuts: <kbd>N</kbd> new task, <kbd>/</kbd> search, <kbd>Esc</kbd> cancel editing.</p>
    </footer>
</div>

<div class="toast<?= $flash ? ' is-visible' : '' ?>" id="toast"
     data-type="<?= e($flash['type'] ?? 'success') ?>"
     role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>">
    <span id="toastMessage"><?= e($flash['message'] ?? '') ?></span>
    <button type="button" class="toast-close" id="toastClose" aria-label="Dismiss message" hidden>&times;</button>
</div>

<dialog class="dialog" id="confirmDialog" aria-labelledby="confirmTitle">
    <form method="dialog">
        <h2 class="dialog-title" id="confirmTitle">Are you sure?</h2>
        <p class="dialog-text" id="confirmText"></p>
        <div class="dialog-actions">
            <button type="submit" class="btn" value="cancel">Cancel</button>
            <button type="submit" class="btn btn-danger-solid" value="confirm" id="confirmOk">Confirm</button>
        </div>
    </form>
</dialog>
</body>
</html>
