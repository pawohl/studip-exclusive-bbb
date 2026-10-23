// E2E tests for ExclusiveBBB against the dev stack. Expects the booking created by
// `booking_flow.php --keep-now`, its details copied to dev/screenshots/now.json.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const shots = path.join(__dirname, '../../dev/screenshots');
const now = JSON.parse(fs.readFileSync(path.join(shots, 'now.json'), 'utf8'));
const joinPath = `/plugins.php/exclusivebbb/join/index/${now.token}`;

async function login(page, username) {
    await page.goto('/index.php?again=yes');
    await page.fill('#loginname', username);
    await page.fill('#password', 'testing');
    await page.press('#password', 'Enter');
    await expect(page.locator('#login-form')).toHaveCount(0);
    // demo users have to accept the terms of use once
    const accept = page.getByRole('button', { name: 'Ich erkenne die Nutzungsbedingungen an' });
    if (await accept.count()) {
        await accept.click();
    }
}

async function joinWith(page, name, password) {
    await page.goto(joinPath);
    await page.fill('#exbbb-fullname', name);
    if (password !== null) {
        await page.fill('#exbbb-password', password);
    }
    await page.click('button[type=submit]');
}

test.describe('public join page', () => {
    test('shows the conference and refuses a wrong password', async ({ page }) => {
        await page.goto(joinPath);
        await expect(page.locator('h1')).toHaveText('Senatssitzung (Test)');
        await page.screenshot({ path: path.join(shots, 'join-page.png'), fullPage: true });

        await joinWith(page, 'Erika Gast', 'falsches-passwort');
        await expect(page.locator('.exbbb-error')).toHaveText('Das Passwort ist falsch.');
        await page.screenshot({ path: path.join(shots, 'join-wrong-password.png'), fullPage: true });
    });

    test('attendee password joins as viewer on node 1', async ({ page }) => {
        await joinWith(page, 'Erika Gast', now.attendee);
        await expect(page.locator('#joined')).toContainText('node1');
        await expect(page.locator('#role')).toHaveText('VIEWER');
        await expect(page.locator('#user')).toHaveText('Erika Gast');
    });

    test('moderator password joins as moderator', async ({ page }) => {
        await joinWith(page, 'Max Moderation', now.moderator);
        await expect(page.locator('#role')).toHaveText('MODERATOR');
    });

    test('unknown token shows not found', async ({ page }) => {
        const response = await page.goto('/plugins.php/exclusivebbb/join/index/' + '0'.repeat(32));
        expect(response.status()).toBe(404);
        await expect(page.locator('h1')).toHaveText('Konferenz nicht gefunden');
    });
});

test.describe('logged-in users', () => {
    test('booked lecturer joins as moderator without password', async ({ page }) => {
        await login(page, 'test_dozent');
        await page.goto(joinPath);
        await expect(page.locator('.exbbb-hint').first()).toContainText('Moderator/in');
        await expect(page.locator('#exbbb-password')).toHaveCount(0);
        await page.click('button[type=submit]');
        await expect(page.locator('#role')).toHaveText('MODERATOR');
    });

    test('lecturer sees and edits the conference', async ({ page }) => {
        await login(page, 'test_dozent');
        await page.goto('/plugins.php/exclusivebbb/conferences/index');
        await expect(page.getByRole('link', { name: 'Senatssitzung (Test)' }).first()).toBeVisible();
        await page.screenshot({ path: path.join(shots, 'conferences-list.png'), fullPage: true });

        await page.getByRole('link', { name: 'Senatssitzung (Test)' }).first().click();
        await page.fill('textarea[name=description]', 'Öffentliche Sitzung des Senats. Bitte Mikrofon stumm schalten.');
        await page.click('button[name=accept]');
        await expect(page.getByText('Die Konferenz wurde gespeichert.')).toBeVisible();

        await page.fill('input[name=attendee_password]', 'teilnahme-2026');
        await page.fill('input[name=moderator_password]', 'moderation-2026');
        await page.uncheck('input[name=send_mail]');
        await page.click('button[name=set]');
        await expect(page.getByText('Teilnehmende: teilnahme-2026')).toBeVisible();
        await page.screenshot({ path: path.join(shots, 'conference-edit.png'), fullPage: true });
    });

    test('new attendee password works on the join page', async ({ page }) => {
        await joinWith(page, 'Erika Gast', 'teilnahme-2026');
        await expect(page.locator('#role')).toHaveText('VIEWER');
    });

    test('unrelated student cannot edit the conference', async ({ page }) => {
        await login(page, 'test_autor');
        const response = await page.goto(`/plugins.php/exclusivebbb/conferences/edit/${now.id}`);
        expect(response.status()).toBe(403);
    });

    test('root configures nodes and tests the connection', async ({ page }) => {
        await login(page, 'root@studip');
        await page.goto('/plugins.php/exclusivebbb/admin/index');
        await expect(page.getByRole('cell', { name: 'BBB Exklusiv 1' })).toBeVisible();
        await page.getByTitle('Verbindung testen').first().click();
        await expect(page.getByText(/Verbindung zu BBB Exklusiv 1 erfolgreich/)).toBeVisible();
        await page.screenshot({ path: path.join(shots, 'admin.png'), fullPage: true });
    });

    test('Raumvergabe sees live status in the management view', async ({ page }) => {
        await login(page, 'test_admin');
        await page.goto('/plugins.php/exclusivebbb/management/index');
        await expect(page.getByRole('heading', { name: /BBB Exklusiv 1/ })).toBeVisible();
        await expect(page.getByText('Senatssitzung (Test)').first()).toBeVisible();
        await page.screenshot({ path: path.join(shots, 'management.png'), fullPage: true });
    });

    test('Raumvergabe books a node in the booking dialog; the buffer blocks a follow-up', async ({ page }) => {
        // random day so repeated runs do not collide with earlier bookings
        const day = new Date(Date.now() + (30 + Math.floor(Math.random() * 300)) * 86400000);
        const date = day.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const name = `Rektoratssitzung ${date}`;

        async function book(begin, end, description) {
            await page.goto(`/dispatch.php/resources/booking/add/${now.resource}`);
            // the end date field is only shown for multi-day bookings
            await page.fill('#BookingStartDateInput', date);
            await page.keyboard.press('Escape');
            for (const [selector, value] of [['#BookingStartTimeInput', begin], ['#BookingEndTimeInput', end]]) {
                await page.locator(selector).evaluate((input, v) => {
                    input.value = v;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }, value);
            }
            await page.fill('textarea[name=description]', description);
            await Promise.all([
                page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/booking/add/')),
                page.click('button[name=save]'),
            ]);
            await page.waitForLoadState('networkidle');
        }

        await login(page, 'test_admin');
        await book('09:00', '10:00', name);
        await page.goto('/plugins.php/exclusivebbb/conferences/index');
        await expect(page.getByRole('link', { name }).first()).toBeVisible();

        // 10:05 is inside the 15 minute overrun buffer of the first booking
        await book('10:05', '11:00', name + ' (zu knapp)');
        await page.screenshot({ path: path.join(shots, 'booking-buffer-refused.png'), fullPage: true });
        await expect(page.getByText(/Gebucht im Bereich/).first()).toBeVisible();
    });
});
