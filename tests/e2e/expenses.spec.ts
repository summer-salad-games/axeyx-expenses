import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

test.use({ actionTimeout: 10000 });

async function login(page: Page, project: string, role: string) {
    const user = JSON.parse(readFileSync('.data/e2e/accounts.json', 'utf8'))[`${project}_m3_${role}`];
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(user.email);
    await page.getByLabel(/mot de passe/i).and(page.locator('input')).fill(user.password);
    await page.getByRole('button', { name: 'Connexion', exact: true }).click();
    await page.getByRole('button', { name: 'Utiliser un code de récupération à la place' }).click();
    await page.getByLabel('Ou, entrez un code de récupération').fill(user.recoveryCodes[0]);
    await page.getByRole('button', { name: 'Confirmer la connexion', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
}

async function selectCategory(page: Page, name: string) {
    await page.locator('.fi-fo-select-wrp').scrollIntoViewIfNeeded();
    await page.getByRole('button', { name: /^Catégorie/ }).click();
    await page.getByRole('option', { name, exact: true }).click();
}

async function searchSelect(page: Page, field: string, search: string, option: string) {
    await page.getByRole('button', { name: field, exact: true }).and(page.locator('.fi-select-input-btn')).click();
    await page.getByRole('combobox', { name: new RegExp(`^${field}:`) }).fill(search);
    await page.getByRole('option', { name: option, exact: true }).click();
}

async function expenseForm(page: Page, category: string, amount: string, description: string) {
    await page.getByLabel('Date de la dépense', { exact: false }).fill('2026-11-12');
    await page.getByRole('textbox', { name: 'Montant', exact: false }).fill(amount);
    await selectCategory(page, category);
    await page.getByRole('textbox', { name: 'Description', exact: true }).fill(description);
}

async function createExpense(page: Page, category: string, amount: string, description: string) {
    await page.goto('/expenses/create');
    await expenseForm(page, category, amount, description);
    await page.getByRole('button', { name: 'Créer', exact: true }).click();
    await expect(page).toHaveURL(/\/expenses\/\d+$/);
    return new URL(page.url()).pathname;
}

async function removeExpense(page: Page) {
    await page.getByRole('button', { name: 'Supprimer', exact: true }).click();
    const dialog = page.getByRole('alertdialog');
    await expect(dialog.locator('.fi-modal-window')).toBeVisible();
    await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
    await expect(page).toHaveURL(/\/expenses$/);
}

test('administrator, editor and viewer expense/category workflows with stale forms and shared visibility', async ({ page: admin, browser }, info) => {
    test.setTimeout(120000);
    const contextOptions = {
        baseURL: 'http://127.0.0.1:8011', viewport: admin.viewportSize(),
        isMobile: info.project.name === 'mobile', hasTouch: info.project.name === 'mobile',
    };
    const editorContext = await browser.newContext(contextOptions);
    const viewerContext = await browser.newContext(contextOptions);
    const editor = await editorContext.newPage();
    const viewer = await viewerContext.newPage();
    const errors: string[] = [];
    for (const page of [admin, editor, viewer]) {
        page.on('response', response => {
            if (response.status() >= 500) errors.push(`${response.status()} ${new URL(response.url()).pathname}`);
        });
    }
    const category = `Fournitures ${info.project.name}`;
    try {
        await login(admin, info.project.name, 'admin');
        await admin.goto('/expenses');
        await expect(admin.getByText('Aucune dépense', { exact: true })).toBeVisible();
        await admin.goto('/categories');
        await admin.locator('.fi-ta-search-field input').fill('Maintenance et étalonnage');
        await expect(admin.getByRole('link', { name: 'Maintenance et étalonnage', exact: true })).toBeVisible();
        await admin.getByRole('button', { name: /^Filtre/ }).click();
        await searchSelect(admin, 'Statut', 'Actives', 'Actives');
        await admin.getByRole('button', { name: 'Appliquer les filtres', exact: true }).click();
        await expect(admin.getByText('Statut: Actives', { exact: false })).toBeVisible();
        await admin.goto('/categories/create');
        await admin.getByRole('textbox', { name: 'Nom', exact: false }).fill(category);
        await admin.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        const categoryRow = admin.getByRole('row').filter({ hasText: category });
        await expect(categoryRow).toBeVisible();
        const categoryEdit = await categoryRow.getByRole('link', { name: 'Modifier', exact: true }).getAttribute('href');
        expect(categoryEdit).toBeTruthy();
        await admin.goto('/categories/create');
        await admin.getByRole('textbox', { name: 'Nom', exact: false }).fill(` ${category.toUpperCase()} `);
        await admin.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(admin.locator('[data-validation-error]')).toContainText('Ce nom de catégorie est déjà utilisé');

        await login(editor, info.project.name, 'editor');
        expect((await editor.goto('/categories'))?.status()).toBe(403);
        await editor.goto('/expenses/create');
        await expenseForm(editor, category, '0', `Achat ${info.project.name} <b>texte simple</b>`);
        await editor.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(editor.locator('[data-validation-error]')).toContainText('Le montant doit être compris');
        const amount = editor.getByRole('textbox', { name: 'Montant', exact: false });
        await amount.fill('');
        await amount.pressSequentially('ab12c.34d5e6');
        await expect(amount).toHaveValue('12.345');
        await amount.blur();
        await expect(amount).toHaveValue('12,345');
        for (const invalid of ['12.5001', '1,234.567', '1e3', '-12']) {
            const allowed = await amount.evaluate((element: HTMLInputElement, text) => {
                element.select();
                const clipboardData = new DataTransfer();
                clipboardData.setData('text', text);
                return element.dispatchEvent(new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true }));
            }, invalid);
            expect(allowed).toBe(false);
            await expect(amount).toHaveValue('12,345');
        }
        await amount.fill('');
        await amount.pressSequentially('.5');
        await expect(amount).toHaveValue('0.5');
        await amount.blur();
        await expect(amount).toHaveValue('0,500');
        await amount.fill('999999999.999');
        await amount.blur();
        await expect(amount).toHaveValue('999999999,999');
        await amount.focus();
        expect(await amount.evaluate((element: HTMLInputElement) => [element.selectionStart, element.selectionEnd])).toEqual([0, 13]);
        await editor.getByRole('textbox', { name: 'Montant', exact: false }).fill('12.5');
        await amount.blur();
        await expect(amount).toHaveValue('12,500');
        await editor.getByRole('button', { name: 'Créer & Ajouter un autre', exact: true }).click();
        await expect(editor).toHaveURL(/\/expenses\/create$/);
        await expect(amount).toHaveValue('');
        await expect(editor.getByRole('textbox', { name: 'Description', exact: true })).toHaveValue('');
        await expenseForm(editor, category, '1,125', 'Deuxième saisie');
        await editor.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(editor).toHaveURL(/\/expenses\/\d+$/);
        await expect(editor.getByText('1,125 TND', { exact: true })).toBeVisible();
        await removeExpense(editor);
        const firstRow = editor.getByRole('row').filter({ hasText: '12,500 TND' });
        const firstUrl = await firstRow.getByRole('link', { name: 'Voir', exact: true }).getAttribute('href');
        expect(firstUrl).toBeTruthy();
        const editorExpense = new URL(firstUrl!).pathname;
        await editor.goto(editorExpense);
        await expect(editor.getByText('12,500 TND', { exact: true })).toBeVisible();
        await expect(editor.getByText(`Achat ${info.project.name} <b>texte simple</b>`, { exact: true })).toBeVisible();

        await login(viewer, info.project.name, 'viewer');
        await viewer.goto('/expenses');
        await expect(viewer.getByRole('link', { name: /Créer.*dépense/ })).toHaveCount(0);
        await expect(viewer.getByText('12,500 TND', { exact: true })).toBeVisible();
        await viewer.goto(editorExpense);
        await expect(viewer.getByRole('link', { name: 'Modifier', exact: true })).toHaveCount(0);
        await expect(viewer.getByRole('button', { name: 'Supprimer', exact: true })).toHaveCount(0);
        expect((await viewer.goto(`${editorExpense}/edit`))?.status()).toBe(403);
        expect((await viewer.goto('/expenses/create'))?.status()).toBe(403);

        // A second browser disables a category while the editor has an unsaved create form.
        await editor.goto('/expenses/create');
        await expenseForm(editor, category, '1,001', 'Formulaire périmé');
        await admin.goto(categoryEdit!);
        await admin.getByRole('switch', { name: 'Catégorie active', exact: false }).uncheck();
        await admin.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        await editor.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(editor.locator('[data-validation-error]')).toBeVisible();
        await editor.goto(`${editorExpense}/edit`);
        await expect(editor.locator('.fi-select-input-value-label')).toHaveText(`${category} (désactivée)`);
        await editor.getByRole('textbox', { name: 'Montant', exact: false }).fill('0,001');
        await editor.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(editor).toHaveURL(new RegExp(`${editorExpense}$`));
        await expect(editor.getByText('0,001 TND', { exact: true })).toBeVisible();

        // Existing history stays available, and administrators may edit another creator's expense.
        await admin.goto(`${editorExpense}/edit`);
        await admin.getByRole('textbox', { name: 'Montant', exact: false }).fill('2.001');
        await admin.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(admin).toHaveURL(new RegExp(`${editorExpense}$`));
        await viewer.goto(editorExpense);
        await expect(viewer.getByText('2,001 TND', { exact: true })).toBeVisible();
        await viewer.screenshot({ path: `.artifacts/m3-details-${info.project.name}.png`, fullPage: true });

        await admin.goto(categoryEdit!);
        await admin.getByRole('switch', { name: 'Catégorie active', exact: false }).check();
        await admin.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        const adminExpense = await createExpense(admin, category, '7,123', `Dépense administrateur ${info.project.name}`);
        await editor.goto(adminExpense);
        await expect(editor.getByRole('link', { name: 'Modifier', exact: true })).toHaveCount(0);
        await expect(editor.getByRole('button', { name: 'Supprimer', exact: true })).toHaveCount(0);
        expect((await editor.goto(`${adminExpense}/edit`))?.status()).toBe(403);

        await viewer.goto('/expenses');
        await viewer.locator('.fi-ta-search-field input').fill('administrateur');
        await expect(viewer.getByText('7,123 TND', { exact: true })).toBeVisible();
        await expect(viewer.getByText('2,001 TND', { exact: true })).toHaveCount(0);
        await viewer.locator('.fi-ta-search-field input').fill('');
        await expect(viewer.getByText('2,001 TND', { exact: true })).toBeVisible();
        await viewer.getByRole('button', { name: /^Filtre/ }).click();
        await searchSelect(viewer, 'Catégorie', `Fournitures ${info.project.name}`, category);
        await searchSelect(viewer, 'Créée par', 'Éditeur', `Éditeur ${info.project.name}`);
        await viewer.getByRole('button', { name: 'Appliquer les filtres', exact: true }).click();
        await expect(viewer.getByText('2,001 TND', { exact: true })).toBeVisible();
        await expect(viewer.getByText('7,123 TND', { exact: true })).toHaveCount(0);
        expect(await viewer.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewer.viewportSize()!.width);
        await viewer.screenshot({ path: `.artifacts/m3-list-${info.project.name}.png`, fullPage: true });

        await editor.goto(editorExpense);
        await removeExpense(editor);
        expect((await viewer.goto(editorExpense))?.status()).toBe(404);
        const otherExpense = await createExpense(editor, category, '3', 'Suppression par administrateur');
        await admin.goto(otherExpense);
        await removeExpense(admin);
        await admin.goto(adminExpense);
        await removeExpense(admin);
        await expect(admin.getByText('Aucune dépense', { exact: true })).toBeVisible();
        expect(errors).toEqual([]);
    } finally {
        await editorContext.close();
        await viewerContext.close();
    }
});
