# ExclusiveBBB (Stud.IP plugin)

Exclusively bookable BigBlueButton nodes for Stud.IP 6.2, booked as rooms in the
Raumverwaltung. Plan and decisions: see README "Design".

## Layout
- `ExclusiveBBB.php` – SystemPlugin: autoloader (`lib/` = namespace `ExclusiveBBB`), booking observers, navigation.
- `lib/` – `BBB\Client` (API + checksums), `Schedule` (time windows), `Passwords`,
  `Models\{Node,Conference}`, `Services\{BookingObserver,ConferenceService,JoinService,Enforcer,Mailer}`.
- `controllers/` + `views/` – Trails: `join` (public, Nobody role), `conferences` (editors),
  `admin` (root), `management` (root + node resource admins/tutors).
- `cronjobs/exclusivebbb_enforcer.php` – runs every minute, ends meetings outside booking windows.
- `migrations/` – tables `exbbb_*`, config `EXBBB_*`, log actions `EXBBB_*`, Nobody role, cronjob.

## Key facts
- Stud.IP stores `resource_booking_intervals` *including* preparation/subsequent time and
  recreates them on every booking edit (interval IDs are not stable) → conferences are keyed by booking.
- `ResourceBookingWillValidate` fires *after* the core overlap check; the observer raises
  prep/subsequent time and calls `validate()` again so the overlap check sees the buffer.
- BBB join uses `role=MODERATOR|VIEWER`; user passwords are checked in PHP and never sent to BBB.
- Plugins get all system roles except `Nobody`; migration 001 adds it for the public join page.

## Dev / tests
- Stack: `docker compose -f dev/docker-compose.yml up -d` (Stud.IP 6.2 from `../studip`, port 8000,
  Mailpit, BBB mocks `bbb-node1`/`bbb-node2` on :8090), then `dev/setup.sh`.
- Unit tests run in the web container (PHP 8.1):
  `docker compose -f dev/docker-compose.yml exec -w /var/www/studip/public/plugins_packages/exclusivebbb/ExclusiveBBB web vendor/bin/phpunit`
  (install dev deps once with the composer command in README).
- Cronjob manually: `docker compose -f dev/docker-compose.yml exec web php cli/studip cronjobs:execute <task_id>` or see README.
- Stop the stack when not in use. Synthetic data only.
