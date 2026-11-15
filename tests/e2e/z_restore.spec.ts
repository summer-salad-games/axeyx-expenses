import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';

function run(action: string, project: string) {
    execFileSync('php', ['tests/Support/backup-e2e.php', action, project], { stdio: 'pipe', timeout: 30000 });
}
function account(project: string, kind: string) {
    return JSON.parse(readFileSync('.data/e2e/accounts.json', 'utf8'))[`${project}_restore_${kind}`];
}
async function passwordLogin(page: Page, email: string, password: string) {
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(email);
    await page.getByLabel(/mot de passe/i).and(page.locator('input')).fill(password);
    await page.getByRole('button', { name: 'Connexion', exact: true }).click();
}
function totp(secret: string) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret].map(c => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g)!.map(value => parseInt(value, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hash = createHmac('sha1', key).update(counter).digest();
    return ((hash.readUInt32BE(hash[19] & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

test('restored financial data requires fresh MFA and passwords while old sessions and disabled accounts stay denied', async ({ page, browser }, info) => {
    test.setTimeout(120000);
    run('prepare', info.project.name);
    const original = account(info.project.name, 'admin');
    const context = JSON.parse(readFileSync(`.data/e2e/restore-context-${info.project.name}.json`, 'utf8'));
    try {
        await passwordLogin(page, original.email, original.password);
        await page.getByRole('button', { name: 'Utiliser un code de récupération à la place' }).click();
        await page.getByLabel('Ou, entrez un code de récupération').fill(original.recoveryCodes[0]);
        await page.getByRole('button', { name: 'Confirmer la connexion', exact: true }).click();
        await expect(page).toHaveURL(/\/$/);
        run('restore', info.project.name);
        await page.goto(`/transactions/${context.expense}`);
        await expect(page).toHaveURL(/\/login$/);
        await passwordLogin(page, original.email, original.password);
        await expect(page.locator('[data-validation-error]').first()).toBeVisible();
        run('passwords', info.project.name);
        const restored = account(info.project.name, 'admin');
        await passwordLogin(page, restored.email, restored.temporary);
        await expect(page).toHaveURL(/\/setup\/mfa$/);
        const card = page.locator('.setup-card');
        const secret = (await card.locator('span').allTextContents()).map(value => value.trim()).find(value => /^[A-Z2-7]{16,}$/.test(value))!;
        expect(secret).toBeTruthy();
        expect(secret).not.toBe(original.secret);
        await card.getByRole('textbox', { name: /Entrez le code à 6 chiffres/ }).fill(totp(secret));
        await card.getByRole('button', { name: /suivant/i }).click();
        await expect(card.getByText(/Veuillez enregistrer les codes de récupération/)).toBeVisible();
        await card.getByRole('button', { name: "Activer l'application d'authentification", exact: true }).click();
        await expect(page).toHaveURL(/\/setup\/password$/);
        const chosen = 'nouveau mot de passe après restauration';
        await page.getByLabel('Nouveau mot de passe', { exact: false }).and(page.locator('input')).first().fill(chosen);
        await page.getByRole('textbox', { name: /Confirmer le nouveau mot de passe/i }).fill(chosen);
        await page.getByLabel('Mot de passe actuel', { exact: false }).and(page.locator('input')).fill(restored.temporary);
        await page.getByRole('button', { name: 'Continuer', exact: true }).click();
        await expect(page).toHaveURL(/\/setup\/complete$/);
        await page.getByRole('button', { name: 'Accéder à l’application', exact: true }).click();
        await expect(page.getByTestId('statistics-net')).toContainText('2,002 TND');
        await page.goto(`/transactions/${context.expense}`);
        await expect(page.getByText('Restored expense', { exact: true })).toBeVisible();
        expect((await page.request.get(`/attachments/${context.attachment}/view`)).status()).toBe(200);
        expect((await page.request.get(`/transactions/${context.later}`)).status()).toBe(404);
        const disabledContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8011' });
        const disabledPage = await disabledContext.newPage();
        const disabled = account(info.project.name, 'disabled');
        await passwordLogin(disabledPage, disabled.email, disabled.temporary);
        await expect(disabledPage).toHaveURL(/\/login$/);
        await expect(disabledPage.locator('[data-validation-error]').first()).toBeVisible();
        await disabledContext.close();
    } finally {
        run('cleanup', info.project.name);
    }
});
