import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

test.use({ actionTimeout: 10000 });

const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC', 'base64');
function pdf(): Buffer {
    let text = '%PDF-1.2 \n'; // A readable generator variant with trailing header whitespace.
    const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>', '<< /Length 0 >>\nstream\n\nendstream'];
    const offsets = [0];
    objects.forEach((object, index) => { offsets.push(Buffer.byteLength(text)); text += `${index + 1} 0 obj\n${object}\nendobj\n`; });
    const start = Buffer.byteLength(text);
    text += `xref\n0 5\n0000000000 65535 f \n${offsets.slice(1).map(n => String(n).padStart(10, '0') + ' 00000 n \n').join('')}trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF\n`;
    return Buffer.from(text);
}

async function login(page: Page, project: string, role: string) {
    const user = JSON.parse(readFileSync('.data/e2e/accounts.json', 'utf8'))[`${project}_m4_${role}`];
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(user.email);
    await page.getByLabel(/mot de passe/i).and(page.locator('input')).fill(user.password);
    await page.getByRole('button', { name: 'Connexion', exact: true }).click();
    await page.getByRole('button', { name: 'Utiliser un code de récupération à la place' }).click();
    await page.getByLabel('Ou, entrez un code de récupération').fill(user.recoveryCodes[0]);
    await page.getByRole('button', { name: 'Confirmer la connexion', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
}

async function upload(page: Page, files: { name: string; mimeType: string; buffer: Buffer }[]) {
    await page.locator('input[type=file]').setInputFiles(files);
    await expect(page.locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(files.length, { timeout: 15000 });
}

test('private attachment upload, preview/download, retained edits, roles and confirmed removal', async ({ page: editor, browser }, info) => {
    test.setTimeout(120000);
    const viewerContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8011', viewport: editor.viewportSize(), isMobile: info.project.name === 'mobile', hasTouch: info.project.name === 'mobile' });
    const viewer = await viewerContext.newPage();
    const errors: string[] = [];
    for (const page of [editor, viewer]) page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    try {
        await login(editor, info.project.name, 'editor');
        await login(viewer, info.project.name, 'viewer');
        await editor.goto('/expenses/create');
        await editor.getByLabel('Date de la dépense', { exact: false }).fill('2026-11-13');
        await editor.getByRole('textbox', { name: 'Montant', exact: false }).fill('100,125');
        await editor.getByRole('button', { name: /^Catégorie/ }).click();
        await editor.getByRole('option').first().click();
        await editor.getByRole('textbox', { name: 'Description', exact: true }).fill(`Documents privés ${info.project.name}`);
        await upload(editor, [{ name: 'reçu.png', mimeType: 'image/png', buffer: image }, { name: 'facture.pdf', mimeType: 'application/pdf', buffer: pdf() }]);
        await expect(editor.locator('.filepond--image-preview')).toHaveCount(0);
        await editor.getByRole('button', { name: 'Créer', exact: true }).click();
        await expect(editor).toHaveURL(/\/expenses\/\d+$/);
        const expense = new URL(editor.url()).pathname;
        await expect(editor.getByText('reçu.png', { exact: true })).toBeVisible();
        await expect(editor.getByText('facture.pdf', { exact: true })).toBeVisible();
        const pngRow = editor.getByRole('row').filter({ hasText: 'reçu.png' });
        const pdfRow = editor.getByRole('row').filter({ hasText: 'facture.pdf' });
        const downloadUrl = await pngRow.getByRole('link', { name: 'Télécharger', exact: true }).getAttribute('href');
        const previewUrl = await pngRow.locator('img').getAttribute('src');
        expect(previewUrl).toMatch(/\/attachments\/\d+\/view$/);
        await expect(pngRow.getByRole('link', { name: 'Ouvrir', exact: true })).toHaveAttribute('href', previewUrl!);
        await expect(pngRow.locator('a').filter({ has: editor.locator('img') })).toHaveAttribute('href', previewUrl!);
        const fullImagePromise = editor.waitForEvent('popup');
        await pngRow.getByRole('link', { name: 'Ouvrir', exact: true }).click();
        const fullImage = await fullImagePromise;
        await expect(fullImage).toHaveURL(previewUrl!);
        await expect(fullImage.locator('img')).toHaveJSProperty('naturalWidth', 1);
        await fullImage.close();
        await expect(pngRow.locator('img')).toHaveJSProperty('naturalWidth', 1);
        const downloaded = editor.waitForEvent('download');
        await pngRow.getByRole('link', { name: 'Télécharger', exact: true }).click();
        const file = await downloaded;
        expect(file.suggestedFilename()).toBe('reçu.png');
        expect(createHash('sha256').update(readFileSync((await file.path())!)).digest('hex')).toBe(createHash('sha256').update(image).digest('hex'));
        const pdfUrl = await pdfRow.getByRole('link', { name: 'Télécharger', exact: true }).getAttribute('href');
        const pdfResponse = await editor.request.get(pdfUrl!);
        expect(pdfResponse.status()).toBe(200);
        expect(await pdfResponse.body()).toEqual(pdf());
        expect(pdfResponse.headers()['content-disposition']).toMatch(/^attachment;/);
        const pdfDownload = editor.waitForEvent('download');
        await pdfRow.getByRole('link', { name: 'Télécharger', exact: true }).click();
        expect(readFileSync((await (await pdfDownload).path())!)).toEqual(pdf());
        const viewUrl = await pdfRow.getByRole('link', { name: 'Ouvrir', exact: true }).getAttribute('href');
        expect(viewUrl).toMatch(/\/attachments\/\d+\/view$/);
        const inline = await editor.request.get(viewUrl!);
        expect(inline.headers()['content-security-policy']).toContain('sandbox');
        expect(inline.headers()['content-disposition']).toMatch(/^inline;/);
        await viewer.goto(expense);
        await expect(viewer.getByText('reçu.png', { exact: true })).toBeVisible();
        await expect(viewer.getByRole('button', { name: 'Retirer', exact: true })).toHaveCount(0);
        expect((await viewer.request.get(downloadUrl!)).status()).toBe(200);
        expect((await browser.newContext().then(async context => { try { return await context.request.get(downloadUrl!, { maxRedirects: 0 }); } finally { await context.close(); } })).status()).toBe(302);
        await editor.goto(`${expense}/edit`);
        await editor.getByRole('textbox', { name: 'Description', exact: true }).fill(`Documents conservés ${info.project.name}`);
        await editor.getByRole('button', { name: 'Sauvegarder les modifications', exact: true }).click();
        await expect(editor).toHaveURL(new RegExp(`${expense}$`));
        await expect(editor.getByText('reçu.png', { exact: true })).toBeVisible();
        await expect(editor.getByText('facture.pdf', { exact: true })).toBeVisible();
        await editor.screenshot({ path: `.artifacts/attachments-${info.project.name}.png`, fullPage: true });
        await pngRow.getByRole('button', { name: 'Retirer', exact: true }).click();
        const dialog = editor.getByRole('alertdialog');
        await expect(dialog.locator('.fi-modal-window')).toBeVisible();
        await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
        await expect(dialog.locator('.fi-modal-window')).toBeHidden();
        await expect(editor.getByText('reçu.png', { exact: true })).toBeVisible();
        await pngRow.getByRole('button', { name: 'Retirer', exact: true }).click();
        await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
        await expect(editor.getByText('reçu.png', { exact: true })).toHaveCount(0);
        expect((await viewer.request.get(downloadUrl!)).status()).toBe(404);
        await editor.getByRole('button', { name: 'Supprimer', exact: true }).click();
        await expect(dialog.getByText('Cette action est irréversible. La dépense et toutes ses pièces jointes seront supprimées définitivement.', { exact: true })).toBeVisible();
        await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
        await expect(dialog.locator('.fi-modal-window')).toBeHidden();
        await expect(editor).toHaveURL(new RegExp(`${expense}$`));
        await expect(editor.getByText('facture.pdf', { exact: true })).toBeVisible();
        await editor.getByRole('button', { name: 'Supprimer', exact: true }).click();
        await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
        await expect(editor).toHaveURL(/\/expenses$/);
        expect((await viewer.request.get(pdfUrl!)).status()).toBe(404);
        expect(errors).toEqual([]);
    } finally { await viewerContext.close(); }
});
