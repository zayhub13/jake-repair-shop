Jake's Cellphone Repair Shop - setup and deployment

LOCAL SETUP
1. Copy the repair_shop folder into the XAMPP htdocs directory.
2. Import cellphone_repair.sql into MySQL to create the initial schema.
   The script does not drop an existing database and is not an upgrade script.
   The application applies its limited schema migrations on startup; back up
   existing installations before deploying the updated code.
3. Configure the REPAIR_SHOP_DB_* environment variables for MySQL. For a local
   XAMPP installation, the default database username is root and the password
   is blank. Do not use the MySQL root account on a live installation.
4. Set REPAIR_SHOP_INITIAL_ADMIN_PASSWORD to a unique password of at least
   12 characters, then run setup.php from PowerShell:
   $env:REPAIR_SHOP_INITIAL_ADMIN_PASSWORD = 'choose-a-unique-password'
   & C:\xampp\php\php.exe C:\xampp\htdocs\repair_shop\setup.php
   The setup script only runs from the command line and never uses a default
   password. Change the password after the first login.
5. Open /repair_shop/ in the browser. Staff sign in at /repair_shop/login.php.

PRODUCTION CHECKLIST
- Use a dedicated database account with only the permissions the app needs.
- Configure REPAIR_SHOP_DB_HOST, REPAIR_SHOP_DB_NAME, REPAIR_SHOP_DB_USER,
  and REPAIR_SHOP_DB_PASS through the server environment.
- Set REPAIR_SHOP_APP_SECRET to a long, unique random secret.
- Set REPAIR_SHOP_PUBLIC_URL to the trusted public origin, including scheme
  (for example https://repairs.example.com). Do not derive reset links from
  the incoming Host header.
- Password reset email uses PHP mail(). Configure and test the server's mail
  transport before enabling password recovery. Reset links are never shown
  in the browser.
- Configure an SMS/email delivery provider and a worker before promising
  external notifications. Until a sender is installed, SMS/email messages
  remain pending; the application does not claim they were sent.
- Serve the site over HTTPS. Session cookies are HttpOnly and SameSite=Lax,
  and are Secure when HTTPS is detected.
- Back up the database before applying schema changes or importing SQL.
- Legacy sequential JCRS-numbered jobs are no longer available through public
  tracking because their references were guessable. Staff can retrieve them
  from the admin panel; customers need a verified account-linking workflow
  before old jobs should be exposed again.
- On the first request after deployment, the app unlinks existing customer
  accounts from customer records because those links were created without
  verifying email or phone ownership. Existing repairs remain in staff view;
  customers start a fresh linked history with their next request.
