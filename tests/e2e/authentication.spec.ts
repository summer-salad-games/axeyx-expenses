import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';

type Account = { email: string; password: string; secret: string; recoveryCodes: string[] };
function account(project: string, flow: string): Account {
    return JSON.parse(readFileSync('.data/e2e/accounts.json', 'utf8'))[`${project}_${flow}`];
}
function totp(secret: string): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret.toUpperCase()].map(c => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
    const bytes = Buffer.from(bits.match(/.{8}/g)!.map(byte => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hash = createHmac('sha1', bytes).update(counter).digest();
    const offset = hash[19] & 15;
    return ((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
async function passwordLogin(page: Page, user: Account) {
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(user.email);
    await page.getByLabel(/mot de passe/i).and(page.locator('input')).fill(user.password);
    await page.getByRole('button', { name: 'Connexion', exact: true }).click();
}
async function logout(page: Page) {
    await page.getByRole('button', { name: /menu utilisateur/i }).click();
    await page.getByRole('menu', { name: /menu utilisateur/i }).getByRole('menuitem', { name: /déconnexion/i }).click();
    await expect(page).toHaveURL(/\/login$/);
}

async function openNavigation(page: Page) {
    const navigation = page.getByRole('navigation', { name: 'Navigation principale', exact: true });
    const openButton = page.getByRole('button', { name: 'Agrandir la barre latérale', exact: true });
    if (await openButton.isVisible()) {
        await openButton.click();
    }
    await expect(navigation).toBeInViewport();
    return navigation;
}

async function checkPinnedHeader(page: Page, selector: string) {
    const header = page.locator(selector);
    await expect(header).toHaveCSS('position', 'sticky');
    // Even short desktop pages must prove the header remains visible during real document scrolling.
    const previousMinHeight = await page.evaluate(() => {
        const element = document.querySelector<HTMLElement>('.setup-flow') ?? document.body;
        const previous = element.style.minHeight;
        element.style.minHeight = '200vh';
        return previous;
    });
    await page.evaluate(() => window.scrollTo(0, 300));
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(100);
    await expect.poll(async () => (await header.boundingBox())?.y).toBe(0);
    await expect(header).toBeInViewport();
    await page.evaluate(previous => {
        window.scrollTo(0, 0);
        (document.querySelector<HTMLElement>('.setup-flow') ?? document.body).style.minHeight = previous;
    }, previousMinHeight);
}

async function enrollAuthenticator(page: Page, user: Account, requiresPassword = false) {
    await expect(page).toHaveURL(/\/setup\/mfa$/);
    await checkPinnedHeader(page, '.setup-header');
    await page.goto('/setup');
    await expect(page).toHaveURL(/\/setup\/mfa$/);
    await expect(page.getByRole('button', { name: /menu utilisateur/i })).toHaveCount(0);
    const dialog = page.locator('.setup-card');
    await expect(dialog.getByRole('img', { name: /QR code à scanner/ })).toBeVisible();
    const secret = (await dialog.locator('span').allTextContents()).map(x => x.trim()).find(x => /^[A-Z2-7]{16,}$/.test(x));
    expect(secret).toBeTruthy();
    const password = dialog.getByLabel('Mot de passe actuel').and(page.locator('input'));
    if (requiresPassword) {
        await password.fill(user.password);
    } else {
        await expect(password).toHaveCount(0);
    }
    await expect(dialog.locator('.fi-one-time-code-input-digit').last()).toHaveValue('');
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('DOM.enable');
    await cdp.send('CSS.enable');
    const { root } = await cdp.send('DOM.getDocument');
    const { nodeId } = await cdp.send('DOM.querySelector', { nodeId: root.nodeId, selector: '.fi-one-time-code-input-digit:last-child' });
    await cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: ['autofill'] });
    await expect(dialog.locator('.fi-one-time-code-input-digit').last()).toHaveCSS('box-shadow', /rgb\(255, 255, 255\)/);
    await page.screenshot({ path: `.artifacts/setup-mfa-${requiresPassword ? 'reset-' : ''}${test.info().project.name}.png`, fullPage: true, animations: 'disabled', mask: [dialog.getByRole('img', { name: /QR code à scanner/ }), dialog.getByText(secret!, { exact: true })] });
    await cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] });
    await cdp.detach();
    if (! requiresPassword) {
        await dialog.getByRole('textbox', { name: /Entrez le code à 6 chiffres/ }).fill(totp(secret!) === '000000' ? '111111' : '000000');
        await dialog.getByRole('button', { name: /suivant/i }).click();
        await expect(dialog.locator('[data-validation-error]')).toContainText('Le code est incorrect ou a expiré');
    }
    await dialog.getByRole('textbox', { name: /Entrez le code à 6 chiffres/ }).fill(totp(secret!));
    await dialog.getByRole('button', { name: /suivant/i }).click();
    await expect(dialog.getByText(/Veuillez enregistrer les codes de récupération/)).toBeVisible();
    const recoveryCodes = (await dialog.locator('li').allTextContents()).map(value => value.trim()).filter(value => /^[A-Za-z0-9]{10}-[A-Za-z0-9]{10}$/.test(value));
    expect(recoveryCodes).toHaveLength(8);
    const [activation] = await Promise.all([
        page.waitForResponse(response => response.request().method() === 'POST'
            && response.url().includes('/livewire-')
            && response.request().postDataJSON().components?.some((component: any) => component.calls?.some((call: any) => call.method === 'callMountedAction'))),
        dialog.getByRole('button', { name: "Activer l'application d'authentification", exact: true }).click(),
    ]);
    await activation.finished();
    return { secret: secret!, recoveryCodes };
}

async function setupSignout(page: Page) {
    await page.getByRole('button', { name: 'Se déconnecter', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
}

async function recoveryLogin(page: Page, user: Account, recoveryCode: string) {
    await passwordLogin(page, user);
    await page.getByRole('button', { name: 'Utiliser un code de récupération à la place' }).click();
    await page.getByLabel('Ou, entrez un code de récupération').fill(recoveryCode);
    await page.getByRole('button', { name: 'Confirmer la connexion', exact: true }).click();
}

test('password + TOTP, logout and recovery login', async ({ page }, info) => {
    const user = account(info.project.name, 'login');
    await passwordLogin(page, user);
    await expect(page.getByRole('heading', { name: 'Vérifier votre identité' })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Se souvenir de moi' })).toHaveCount(0);
    await page.getByRole('textbox', { name: /Entrez le code à 6 chiffres/ }).fill(totp(user.secret));
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
    let navigation = await openNavigation(page);
    await expect(navigation.getByRole('link', { name: 'Tableau de bord', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(navigation.getByRole('link', { name: 'Paramètres du compte', exact: true })).not.toHaveAttribute('aria-current', 'page');
    await navigation.getByRole('link', { name: 'Paramètres du compte', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Paramètres du compte', exact: true })).toBeVisible();
    navigation = await openNavigation(page);
    await expect(navigation.getByRole('link', { name: 'Paramètres du compte', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(navigation.getByRole('link', { name: 'Tableau de bord', exact: true })).not.toHaveAttribute('aria-current', 'page');
    await page.screenshot({ path: `.artifacts/settings-navigation-${info.project.name}.png`, fullPage: info.project.name === 'desktop', animations: 'disabled' });
    await navigation.getByRole('link', { name: 'Tableau de bord', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Tableau de bord', exact: true })).toBeVisible();
    await page.getByRole('button', { name: /menu utilisateur/i }).click();
    const userMenu = page.getByRole('menu', { name: /menu utilisateur/i });
    await expect(userMenu.getByRole('menuitem', { name: 'Paramètres du compte', exact: true })).toHaveCount(0);
    await expect(userMenu.getByRole('menuitem', { name: /déconnexion/i })).toBeVisible();
    await page.keyboard.press('Escape');
    await checkPinnedHeader(page, '.fi-topbar-ctn');
    await logout(page);
    await passwordLogin(page, user);
    await page.getByRole('button', { name: 'Utiliser un code de récupération à la place' }).click();
    await page.getByLabel('Ou, entrez un code de récupération').fill(user.recoveryCodes[0]);
    await page.getByRole('button', { name: 'Confirmer la connexion', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
    await logout(page);
});

test('first login requires enrollment; complete wizard and change password securely', async ({ page }, info) => {
    test.setTimeout(60000);
    const methodErrors: string[] = [];
    page.on('response', response => {
        if (response.status() === 405) methodErrors.push(`${response.request().method()} ${new URL(response.url()).pathname}`);
    });
    const user = account(info.project.name, 'setup');
    await passwordLogin(page, user);
    const enrollment = await enrollAuthenticator(page, user);
    await expect(page).toHaveURL(/\/setup\/password$/, { timeout: 10000 });
    expect(methodErrors).toEqual([]);
    await expect(page.getByRole('heading', { name: 'Choisir votre mot de passe' })).toBeVisible();
    await setupSignout(page);
    await recoveryLogin(page, user, enrollment.recoveryCodes[0]);
    await expect(page).toHaveURL(/\/setup\/password$/);
    await checkPinnedHeader(page, '.setup-header');
    await page.goto('/');
    await expect(page).toHaveURL(/\/setup\/password$/);
    await expect(page.getByRole('button', { name: /menu utilisateur/i })).toHaveCount(0);
    await expect(page.locator('.setup-step[aria-current=step]')).toHaveText('Mot de passe');
    await page.screenshot({ path: `.artifacts/setup-password-${info.project.name}.png`, fullPage: true });
    await expect(page.getByRole('button', { name: 'Annuler', exact: true })).toHaveCount(0);
    const chosenPassword = 'une phrase personnelle avec des accents éè';
    await page.getByLabel('Nouveau mot de passe', { exact: false }).and(page.locator('input')).first().fill(chosenPassword);
    await page.getByRole('textbox', { name: /Confirmer le nouveau mot de passe/i }).fill(chosenPassword);
    await page.getByLabel('Mot de passe actuel', { exact: false }).and(page.locator('input')).fill(user.password);
    await page.getByRole('button', { name: 'Continuer', exact: true }).click();
    await expect(page).toHaveURL(/\/setup\/complete$/);
    await expect(page.getByRole('heading', { name: 'Tout est prêt !', exact: true })).toBeVisible();
    await checkPinnedHeader(page, '.setup-header');
    await setupSignout(page);
    await recoveryLogin(page, { ...user, password: chosenPassword }, enrollment.recoveryCodes[1]);
    await expect(page).toHaveURL(/\/setup\/complete$/);
    await expect(page.locator('.setup-step[aria-current=step]')).toHaveText('Tout est prêt');
    await expect(page.getByRole('button', { name: /menu utilisateur/i })).toHaveCount(0);
    await page.screenshot({ path: `.artifacts/setup-complete-${info.project.name}.png`, fullPage: true });
    await page.getByRole('button', { name: 'Accéder à l’application', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
    await page.goto('/profile');
    await expect(page.getByRole('heading', { name: 'Paramètres du compte' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: /e-mail/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /désactiver/i })).toHaveCount(0);
    await expect(page.locator('.fi-topbar')).toBeVisible();
    await expect(page.locator('.fi-simple-main')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Mot de passe', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Double authentification', exact: true })).toBeVisible();
    const newPassword = 'une autre phrase personnelle avec des accents éè';
    await page.getByLabel('Nouveau mot de passe', { exact: false }).and(page.locator('input')).first().fill(newPassword);
    await page.getByRole('textbox', { name: /Confirmer le nouveau mot de passe/i }).fill(newPassword);
    await page.getByLabel('Mot de passe actuel', { exact: false }).and(page.locator('input')).fill(chosenPassword);
    await page.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
    await expect(page.getByText('Sauvegardé', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Paramètres du compte' })).toBeVisible();
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(page.getByRole('navigation', { name: 'En-tête', exact: true }).getByLabel('ProxyMed — Registre des dépenses')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(page.viewportSize()!.width);
    await page.screenshot({ path: `.artifacts/onboarding-settings-${info.project.name}.png`, fullPage: true });
    await logout(page);
});

// This fixture has completed password onboarding and had MFA enrollment cleared.
test('MFA re-enrollment resumes the final setup step', async ({ page }, info) => {
    const user = account(info.project.name, 'mfa_reset');
    await passwordLogin(page, user);
    await enrollAuthenticator(page, user, true);
    await expect(page).toHaveURL(/\/setup\/complete$/);
    await page.getByRole('button', { name: 'Accéder à l’application', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('heading', { name: 'Tableau de bord', exact: true })).toBeVisible();
    await logout(page);
});
