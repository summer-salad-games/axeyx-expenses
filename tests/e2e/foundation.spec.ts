import { test, expect } from '@playwright/test';

test('French login loads locally without exposing application data', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByLabel('ProxyMed — Registre des dépenses')).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByRole('textbox', { name: /e-mail/i })).toBeVisible();
    await expect(page.getByLabel(/mot de passe/i).and(page.locator('input'))).toBeVisible();
    await expect(page.getByRole('button', { name: 'Connexion', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (test.info().project.name === 'mobile') {
        const form = await page.getByRole('main').boundingBox();
        expect(form!.width).toBe(page.viewportSize()!.width);
        const email = await page.getByRole('textbox', { name: /e-mail/i }).boundingBox();
        expect(email!.width).toBeGreaterThan(page.viewportSize()!.width * 0.85);
    }
    expect(errors).toEqual([]);
    await expect(page.locator('.proxymed-brand-mark')).toHaveJSProperty('complete', true);
    expect(await page.locator('.proxymed-brand-mark').evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);
    await page.screenshot({ path: `.artifacts/login-${test.info().project.name}.png` });
    expect((await page.request.get('/storage/attachments/example.pdf')).status()).toBe(404);
});


test('404 remains centered and returns to the shared login', async ({ page }) => {
    const response = await page.goto('/storage/attachments/example.pdf');
    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Page introuvable' })).toBeVisible();
    const card = await page.getByRole('main').boundingBox();
    const viewport = page.viewportSize()!;
    expect(card).not.toBeNull();
    expect(Math.abs(card!.x + card!.width / 2 - viewport.width / 2)).toBeLessThan(3);
    expect(Math.abs(card!.y + card!.height / 2 - viewport.height / 2)).toBeLessThan(3);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: `.artifacts/404-${test.info().project.name}.png` });
    await page.getByRole('link', { name: 'Retour à l’accueil' }).click();
    await expect(page).toHaveURL(/\/login$/);
});
