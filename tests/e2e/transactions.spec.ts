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
    await page.getByRole('button', { name: /^Catégorie/ }).scrollIntoViewIfNeeded();
    await page.getByRole('button', { name: /^Catégorie/ }).click();
    await page.getByRole('option', { name, exact: true }).click();
}

async function searchSelect(page: Page, field: string, search: string, option: string) {
    await page.getByRole('button', { name: field, exact: true }).and(page.locator('.fi-select-input-btn')).click();
    await page.getByRole('combobox', { name: new RegExp(`^${field}:`) }).fill(search);
    await page.getByRole('option', { name: option, exact: true }).click();
}

async function expenseForm(page: Page, category: string, amount: string, description: string) {
    await page.getByLabel('Date de la transaction', { exact: false }).fill('2026-11-12');
    await page.getByRole('textbox', { name: 'Montant', exact: false }).fill(amount);
    await selectCategory(page, category);
    await page.getByRole('textbox', { name: 'Description', exact: true }).fill(description);
}

async function createTransaction(page: Page, category: string, amount: string, description: string) {
    await page.goto('/transactions/create');
    await expenseForm(page, category, amount, description);
    await page.getByRole('button', { name: 'Créer', exact: true }).click();
    await expect(page).toHaveURL(/\/transactions\/\d+$/);
    return new URL(page.url()).pathname;
}

async function removeTransaction(page: Page) {
    await page.getByRole('button', { name: 'Supprimer', exact: true }).click();
    const dialog = page.getByRole('alertdialog');
    await expect(dialog.locator('.fi-modal-window')).toBeVisible();
    await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
    await expect(page).toHaveURL(/\/transactions$/);
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
        await admin.goto('/transactions');
        await expect(admin.getByText('Aucune transaction', { exact: true })).toBeVisible();
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
        await admin.locator('.fi-ta-search-field input').fill(category);
        const categoryRow = admin.getByRole('row').filter({ hasText: category });
        await expect(categoryRow).toBeVisible();
        const categoryEdit = await categoryRow.getByRole('link', { name: 'Modifier', exact: true }).getAttribute('href');
        expect(categoryEdit).toBeTruthy();
        await expect(categoryRow).toContainText('Dépense');
        await admin.getByRole('button', { name: /^Filtre/ }).click();
        await searchSelect(admin, 'Type', 'Recette', 'Recette');
        await admin.getByRole('button', { name: 'Appliquer les filtres', exact: true }).click();
        await expect(categoryRow).toHaveCount(0);
        await admin.locator('.fi-ta-search-field input').fill('Consultations médicales');
        const revenueRow = admin.getByRole('row').filter({ hasText: 'Consultations médicales' });
        await expect(revenueRow).toBeVisible();
        await expect(revenueRow).toContainText('Recette');
        await admin.goto('/categories/create');
        await admin.getByRole('button', { name: /^Type/ }).click();
        await admin.getByRole('option', { name: 'Recette', exact: true }).click();
        await admin.getByRole('textbox', { name: 'Nom', exact: false }).fill(category);
        await admin.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        await admin.locator('.fi-ta-search-field input').fill(category);
        await expect(categoryRow).toHaveCount(2);
        await expect(categoryRow.filter({ hasText: 'Recette' })).toBeVisible();
        await expect(categoryRow.filter({ hasText: 'Dépense' })).toBeVisible();

        await admin.goto('/categories/create');
        await admin.getByRole('textbox', { name: 'Nom', exact: false }).fill(` ${category.toUpperCase()} `);
        await admin.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(admin.locator('[data-validation-error]')).toContainText('Ce nom de catégorie est déjà utilisé');

        const disposableCategory = `À supprimer ${info.project.name}`;
        await admin.goto('/categories/create');
        await admin.getByRole('textbox', { name: 'Nom', exact: false }).fill(disposableCategory);
        await admin.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        await admin.locator('.fi-ta-search-field input').fill(disposableCategory);
        const disposableRow = admin.getByRole('row').filter({ hasText: disposableCategory });
        await expect(disposableRow).toBeVisible();
        await disposableRow.getByRole('button', { name: 'Supprimer', exact: true }).click();
        const categoryDialog = admin.getByRole('alertdialog');
        await expect(categoryDialog.getByText('Cette catégorie n’est utilisée par aucune transaction. Cette action est irréversible.', { exact: true })).toBeVisible();
        await categoryDialog.getByRole('button', { name: 'Annuler', exact: true }).click();
        await expect(categoryDialog.locator('.fi-modal-window')).toBeHidden();
        await expect(disposableRow).toBeVisible();
        await disposableRow.getByRole('button', { name: 'Supprimer', exact: true }).click();
        await categoryDialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
        await expect(disposableRow).toHaveCount(0);
        await admin.locator('.fi-ta-search-field input').fill('');

        await login(editor, info.project.name, 'editor');
        expect((await editor.goto('/categories'))?.status()).toBe(403);
        await editor.goto('/transactions/create');
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
        await expect(editor).toHaveURL(/\/transactions\/create$/);
        await expect(amount).toHaveValue('');
        await expect(editor.getByRole('textbox', { name: 'Description', exact: true })).toHaveValue('');
        await expenseForm(editor, category, '1,125', 'Deuxième saisie');
        await editor.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(editor).toHaveURL(/\/transactions\/\d+$/);
        await expect(editor.getByText('−1,125 TND', { exact: true })).toBeVisible();
        await removeTransaction(editor);
        const firstRow = editor.getByRole('row').filter({ hasText: '−12,500 TND' });
        const firstUrl = await firstRow.getByRole('link', { name: 'Voir', exact: true }).getAttribute('href');
        expect(firstUrl).toBeTruthy();
        const editorTransaction = new URL(firstUrl!).pathname;
        await editor.goto(editorTransaction);
        await expect(editor.getByText('−12,500 TND', { exact: true })).toBeVisible();
        await expect(editor.getByText(`Achat ${info.project.name} <b>texte simple</b>`, { exact: true })).toBeVisible();

        await login(viewer, info.project.name, 'viewer');
        await viewer.goto('/transactions');
        await expect(viewer.getByRole('link', { name: /Créer.*dépense/ })).toHaveCount(0);
        await expect(viewer.getByText('−12,500 TND', { exact: true })).toBeVisible();
        await viewer.goto(editorTransaction);
        await expect(viewer.getByRole('link', { name: 'Modifier', exact: true })).toHaveCount(0);
        await expect(viewer.getByRole('button', { name: 'Supprimer', exact: true })).toHaveCount(0);
        expect((await viewer.goto(`${editorTransaction}/edit`))?.status()).toBe(403);
        expect((await viewer.goto('/transactions/create'))?.status()).toBe(403);

        // A second browser disables a category while the editor has an unsaved create form.
        await editor.goto('/transactions/create');
        await expenseForm(editor, category, '1,001', 'Formulaire périmé');
        await admin.goto(categoryEdit!);
        await expect(admin.getByRole('button', { name: 'Supprimer', exact: true })).toHaveCount(0);
        await admin.getByRole('switch', { name: 'Catégorie active', exact: false }).uncheck();
        await admin.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        await editor.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(editor.locator('[data-validation-error]')).toBeVisible();
        await editor.goto(`${editorTransaction}/edit`);
        await expect(editor.getByRole('button', { name: /^Catégorie/ }).locator('.fi-select-input-value-label')).toHaveText(`${category} (désactivée)`);
        await editor.getByRole('textbox', { name: 'Montant', exact: false }).fill('0,001');
        await editor.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(editor).toHaveURL(new RegExp(`${editorTransaction}$`));
        await expect(editor.getByText('−0,001 TND', { exact: true })).toBeVisible();

        // Existing history stays available, and administrators may edit another creator's expense.
        await admin.goto(`${editorTransaction}/edit`);
        await admin.getByRole('textbox', { name: 'Montant', exact: false }).fill('2.001');
        await admin.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(admin).toHaveURL(new RegExp(`${editorTransaction}$`));
        await viewer.goto(editorTransaction);
        await expect(viewer.getByText('−2,001 TND', { exact: true })).toBeVisible();
        await viewer.screenshot({ path: `.artifacts/m3-details-${info.project.name}.png`, fullPage: true });

        await admin.goto(categoryEdit!);
        await admin.getByRole('switch', { name: 'Catégorie active', exact: false }).check();
        await admin.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(admin).toHaveURL(/\/categories$/);
        const adminTransaction = await createTransaction(admin, category, '7,123', `Dépense administrateur ${info.project.name}`);
        await editor.goto(adminTransaction);
        await expect(editor.getByRole('link', { name: 'Modifier', exact: true })).toHaveCount(0);
        await expect(editor.getByRole('button', { name: 'Supprimer', exact: true })).toHaveCount(0);
        expect((await editor.goto(`${adminTransaction}/edit`))?.status()).toBe(403);

        await viewer.goto('/transactions');
        await viewer.locator('.fi-ta-search-field input').fill('administrateur');
        await expect(viewer.getByText('−7,123 TND', { exact: true })).toBeVisible();
        await expect(viewer.getByText('−2,001 TND', { exact: true })).toHaveCount(0);
        await viewer.locator('.fi-ta-search-field input').fill('');
        await expect(viewer.getByText('−2,001 TND', { exact: true })).toBeVisible();
        await viewer.getByRole('button', { name: /^Filtre/ }).click();
        await searchSelect(viewer, 'Catégorie', `Fournitures ${info.project.name}`, `${category} — Dépense`);
        await searchSelect(viewer, 'Créée par', 'Éditeur', `Éditeur ${info.project.name}`);
        await viewer.getByRole('button', { name: 'Appliquer les filtres', exact: true }).click();
        await expect(viewer.getByText('−2,001 TND', { exact: true })).toBeVisible();
        await expect(viewer.getByText('−7,123 TND', { exact: true })).toHaveCount(0);
        expect(await viewer.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewer.viewportSize()!.width);
        await viewer.screenshot({ path: `.artifacts/m3-list-${info.project.name}.png`, fullPage: true });

        await editor.goto(editorTransaction);
        await removeTransaction(editor);
        expect((await viewer.goto(editorTransaction))?.status()).toBe(404);
        const otherTransaction = await createTransaction(editor, category, '3', 'Suppression par administrateur');
        await admin.goto(otherTransaction);
        await removeTransaction(admin);
        await admin.goto(adminTransaction);
        await removeTransaction(admin);
        await expect(admin.getByText('Aucune transaction', { exact: true })).toBeVisible();
        expect(errors).toEqual([]);
    } finally {
        await editorContext.close();
        await viewerContext.close();
    }
});
