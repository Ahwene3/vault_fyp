# Final Year Project Vault & Collaboration Hub

A PHP/MySQL web application for managing final year projects with role-based access for **Students**, **Supervisors**, **HOD**, and **Admin**.

## Features

- **Authentication & roles**: Login, registration (students), role-based dashboards
- **Student**: Submit project topic, upload documents (PDF/DOCX/ZIP), logbook, view feedback & assessments, messaging
- **Supervisor**: Manage assigned students, review documents, submit assessments, approve/flag logbook entries, messaging
- **HOD**: Approve/reject topics, assign supervisors, archive completed projects, reports
- **Admin**: Manage users (add supervisor/HOD/admin), view vault
- **Project Vault**: Searchable archive of completed projects (by year, topic, student, supervisor)
- **Notifications**: In-app alerts for uploads, feedback, approvals, messages
- **Secure file uploads**: Type and size validation; downloads via PHP with access control

## Requirements

- PHP 7.4+ (with PDO MySQL, sessions)
- MySQL 5.7+ or MariaDB
- Web server (Apache/Nginx) with document root or virtual host pointing to this directory

## Installation

1. **Clone or copy** the project into your web root (e.g. `/var/www/html/vault`).

2. **Create the database** and tables:
   ```bash
   mysql -u root -p < sql/schema.sql
   ```
   Or run the contents of `sql/schema.sql` in phpMyAdmin/MySQL client.

3. **Configure database** in `config/database.php`:
   - Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` for your environment.
   - Set `BASE_PATH` to the URL path to the app (e.g. `/vault` if the app is at `http://localhost/vault`). Use `''` if the app is at the document root.

4. **Create uploads directory** (if not present):
   ```bash
   mkdir -p uploads/projects
   chmod 755 uploads uploads/projects
   ```
   Ensure the web server user can write to `uploads/`.

5. **Default admin account** (change password after first login):
   - Email: `admin@vault.edu`
   - Password: `admin123`

6. **Optional**: Add supervisors/HOD via Admin → Manage Users, or use the same SQL to insert users with role `supervisor` or `hod`.

## Security Notes

- Change the default admin password immediately.
- In production, set `display_errors = 0` and use a proper log for errors.
- Keep `uploads/` outside public path or ensure `.htaccess`/server config prevents execution of PHP in uploads.
- Use HTTPS in production.

## File Structure

```
vault/
├── config/database.php    # DB config & BASE_PATH
├── includes/
│   ├── init.php          # Session, CSRF, flash, helpers
│   ├── auth.php          # Login, roles, require_login
│   ├── header.php        # Nav, alerts
│   ├── footer.php
│   ├── notify.php        # Notification helper
├── assets/css/style.css
├── assets/js/app.js
├── sql/schema.sql
├── uploads/              # Project documents (created at runtime)
├── index.php             # Login
├── register.php          # Student registration
├── logout.php
├── dashboard.php         # Role-based dashboard
├── profile.php
├── messages.php          # Student–supervisor messaging
├── notifications.php
├── vault.php             # Public archive search
├── download.php          # Secure document download
├── student/
│   ├── project.php       # Topic + documents
│   └── logbook.php
├── supervisor/
│   ├── students.php
│   ├── student_detail.php
│   └── logbook_action.php
├── hod/
│   ├── topics.php
│   ├── assign.php
│   ├── archive.php
│   └── reports.php
└── admin/users.php
```

## Usage Summary

1. **Students** register, log in, submit a project topic. After HOD approval and supervisor assignment, they upload documents, maintain the logbook, and message their supervisor.
2. **Supervisors** (added by Admin) see assigned students, give feedback on documents, submit assessments, and approve/flag logbook entries.
3. **HOD** approves/rejects topics, assigns supervisors to approved projects, marks projects completed and archives them, and views reports.
4. **Admin** adds supervisors/HOD, manages user status; all roles can browse the **Project Vault** (archived projects only).

## Git: Push to your remote

The project is already a Git repo with an initial commit. To push to your own Git host (GitHub, GitLab, etc.):

1. **Create a new empty project** on your Git host (do not add a README or .gitignore).

2. **Add the remote and push** (replace `YOUR_USERNAME` and `YOUR_REPO` with your details):

   ```bash
   cd /var/www/html/vault
   git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
   git push -u origin main
   ```

   For GitLab: `https://gitlab.com/YOUR_USERNAME/YOUR_REPO.git`  
   For SSH: `git@github.com:YOUR_USERNAME/YOUR_REPO.git`

## License

Use as needed for educational/institutional purposes.
# vault_fyp
