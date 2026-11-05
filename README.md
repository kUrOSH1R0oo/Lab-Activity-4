# To-do List (COMP016 Lab Activity 4)

A simple task manager built with PHP sessions. Create, read, update and delete tasks with no database and no frameworks.

## Run with Docker

```bash
docker compose up --build
```

Open <http://localhost:1337>.

To use another port, set `PORT` (host and container ports follow it):

```bash
# Linux, macOS, WSL
PORT=3000 docker compose up --build

# PowerShell
$env:PORT=3000; docker compose up --build
```

Without Compose:

```bash
docker build -t todo-app .
docker run -p 3000:3000 -e PORT=3000 todo-app
```

> Avoid ports 6665-6669. Chrome, Edge and the VS Code browser block them with `ERR_UNSAFE_PORT`.

## Run without Docker

Requires PHP 8.1 or newer with `mbstring`.

```bash
php -S localhost:1337
```

Or copy the folder into `C:\laragon\www\` and open it from Laragon.

## Files

| File | Purpose |
| --- | --- |
| `index.php` | Main page: session state, actions, validation, server-rendered list |
| `style.css` | External stylesheet: design tokens, responsive layout, light and dark themes |
| `script.js` | External script: filters, search, inline edit, confirm dialog, toasts, shortcuts |
| `Dockerfile`, `docker-compose.yml` | Container setup |

## How it maps to the lab requirements

| Requirement | Where |
| --- | --- |
| `session_start()` and CRUD in `$_SESSION` | `index.php`, sections 1 and 3 |
| Self-submitting `method="POST"` form, `$_POST`, validation | `index.php`, `read_task_input()` and `handle_post()` |
| PHP loop rendering tasks with echo tags | `index.php`, `foreach ($tasks as $task)` |
| Semantic HTML and external CSS | `header`, `main`, `section`, `footer`, `ul`; `style.css` |
| External JavaScript with DOM events | `script.js` |

## Design decisions

- **Post/Redirect/Get.** Every change is a POST followed by a redirect, so refreshing never re-submits a form.
- **CSRF token.** Each form carries a per-session token that the server verifies.
- **Output escaping.** All user text goes through `htmlspecialchars()` to prevent XSS.
- **Server-side validation.** Titles are trimmed, whitespace-collapsed, and limited to 100 characters. The browser checks first for speed, but the server is the source of truth.
- **Works without JavaScript.** Add, complete and delete still work. JavaScript adds the polish.
- **Container hardening.** Runs as a non-root user with production PHP settings and a health check.

## Notes

- Tasks live in the PHP session, so they clear when the session cookie expires or the container's session files are removed.
- Set `APP_TIMEZONE` (default `Asia/Manila`) to change how timestamps display.
- Shortcuts: `N` new task, `/` search, `Esc` cancel editing.
