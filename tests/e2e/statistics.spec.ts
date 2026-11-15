import { test, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

test.use({ actionTimeout: 10000 });

async function login(page: Page, project: string, role: string) {
    const user = JSON.parse(readFileSync('.data/e2e/accounts.json', 'utf8'))[`${project}_m5_${role}`];
    await page.goto('/login');
    await page.getByRole('textbox', { name: /e-mail/i }).fill(user.email);
    await page.getByLabel(/mot de passe/i).and(page.locator('input')).fill(user.password);
    await page.getByRole('button', { name: 'Connexion', exact: true }).click();
    await page.getByRole('button', { name: 'Utiliser un code de récupération à la place' }).click();
    await page.getByLabel('Ou, entrez un code de récupération').fill(user.recoveryCodes[0]);
    await page.getByRole('button', { name: 'Confirmer la connexion', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
}

async function createTransaction(page: Page, date: string, amount: string, revenue = false) {
    await page.goto('/transactions/create');
    if (revenue) {
        await page.getByRole('button', { name: /^Catégorie/ }).click();
        await page.getByRole('option').first().click();
        await page.getByRole('button', { name: /^Type/ }).click();
        await page.getByRole('option', { name: 'Recette', exact: true }).click();
        await expect(page.getByRole('button', { name: /^Catégorie/ }).locator('.fi-select-input-value-label')).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^Type/ })).toContainText('Recette');
    }
    await page.getByLabel('Date de la transaction', { exact: false }).fill(date);
    await page.getByRole('textbox', { name: 'Montant', exact: false }).fill(amount);
    await page.getByRole('button', { name: /^Catégorie/ }).click();
    await page.getByRole('option').first().click();
    await page.getByRole('button', { name: 'Créer', exact: true }).click();
    await expect(page).toHaveURL(/\/transactions\/\d+$/);
    return new URL(page.url()).pathname;
}

async function openPeriod(page: Page, preset: string) {
    await page.getByRole('button', { name: 'Période', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('.fi-modal-window')).toBeVisible();
    await dialog.getByRole('button', { name: /^Période/ }).click();
    await dialog.getByRole('option', { name: preset, exact: true }).click();
    return dialog;
}

async function expectSummary(page: Page, amount: string, count: number) {
    await expect(page.getByTestId('statistics-expense')).toContainText(amount);
    await expect(page.getByTestId('statistics-count')).toContainText(String(count));
}

test('shared statistics, exact chart data, period validation, empty state and retained filters', async ({ page: admin, browser }, info) => {
    test.setTimeout(120000);
    const viewerContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8011', viewport: admin.viewportSize(), isMobile: info.project.name === 'mobile', hasTouch: info.project.name === 'mobile' });
    const viewer = await viewerContext.newPage();
    const records: string[] = [];
    const errors: string[] = [];
    for (const page of [admin, viewer]) {
        page.on('websocket', () => errors.push('Unexpected application WebSocket connection'));
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    }
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Luxembourg', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    const value = (name: string) => parts.find(part => part.type === name)!.value;
    const year = Number(value('year'));
    const month = value('month');
    const today = `${year}-${month}-${value('day')}`;
    const pastYear = year - 1;
    try {
        await login(admin, info.project.name, 'admin');
        await login(viewer, info.project.name, 'viewer');
        await expectSummary(admin, '0,000 TND', 0);
        await expect(admin.getByText('Aucune transaction sur cette période', { exact: true })).toHaveCount(4);
        for (const [date, amount] of [[today, '1,001'], [`${year}-${month}-01`, '2,002'], [new Date(Date.UTC(year, Number(month) - 1, 0)).toISOString().slice(0, 10), '3,003'], [`${pastYear}-01-01`, '4,004'], [`${pastYear}-03-31`, '5,005']]) {
            records.push(await createTransaction(admin, date, amount));
        }
        await admin.goto('/');
        const viewerPeriod = await openPeriod(viewer, 'Ce mois-ci');
        await viewerPeriod.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(admin, '3,003 TND', 2);
        await expectSummary(viewer, '3,003 TND', 2);
        const viewerTimeline = viewer.locator('[data-chart-type="line"]');
        await viewerTimeline.scrollIntoViewIfNeeded();
        await expect(viewer.getByRole('heading', { name: 'Transactions par jour', exact: true })).toBeVisible();
        await expect.poll(() => viewerTimeline.evaluate(element => {
            const data = (window as any).Alpine.$data(element).getChart()?.data.datasets[1].data;
            return data ? data.reduce((total: bigint, value: string) => total + BigInt(value), 0n).toString() : null;
        })).toBe('3003');
        expect(await viewerTimeline.evaluate(element => (window as any).Alpine.$data(element).getChart().data.datasets[1].data.length)).toBe(new Date(Date.UTC(year, Number(month), 0)).getUTCDate());
        await admin.evaluate(() => window.scrollTo(0, 0));
        await admin.screenshot({ path: `.artifacts/statistics-daily-${info.project.name}.png`, fullPage: true });
        const todayDialog = await openPeriod(admin, 'Aujourd’hui');
        await todayDialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(admin, value('day') === '01' ? '3,003 TND' : '1,001 TND', value('day') === '01' ? 2 : 1);
        const daily = admin.locator('[data-chart-type="line"]');
        await daily.scrollIntoViewIfNeeded();
        await expect.poll(() => daily.evaluate(element => (window as any).Alpine.$data(element).getChart()?.data.datasets[1].data)).toEqual([value('day') === '01' ? '3003' : '1001']);
        await expect(admin.getByRole('table', { name: 'Transactions par jour — valeurs exactes' }).getByRole('row')).toHaveCount(2);
        let dialog = await openPeriod(admin, 'Mois dernier');
        await dialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(admin, '3,003 TND', 1);
        dialog = await openPeriod(admin, 'Personnalisée');
        await dialog.getByLabel('Du', { exact: false }).fill(`${pastYear}-03-31`);
        await dialog.getByLabel('Au', { exact: false }).fill(`${pastYear}-01-01`);
        await dialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expect(dialog.locator('[data-validation-error]').first()).toBeVisible();
        await expectSummary(admin, '3,003 TND', 1);
        await dialog.getByLabel('Du', { exact: false }).fill(`${pastYear}-01-01`);
        await dialog.getByLabel('Au', { exact: false }).fill(`${pastYear}-03-31`);
        await dialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(admin, '9,009 TND', 2);
        await expect(admin.getByText(new RegExp(`Du 01/01/${pastYear} au 31/03/${pastYear}`))).toBeVisible();
        await expectSummary(viewer, '3,003 TND', 2); // Independent browser selection remains unchanged.
        const timeline = admin.locator('[data-chart-type="line"]');
        await timeline.scrollIntoViewIfNeeded();
        await expect.poll(() => timeline.evaluate(element => (window as any).Alpine.$data(element).getChart()?.data.datasets[1].data)).toEqual(['4004', '0', '5005']);
        expect(await timeline.evaluate(element => {
            const chart = (window as any).Alpine.$data(element).getChart();
            return chart.options.plugins.tooltip.callbacks.label({ dataset: chart.data.datasets[1], dataIndex: 0 });
        })).toBe('4,004 TND');
        const table = admin.getByRole('table', { name: 'Transactions par mois — valeurs exactes' });
        await expect(table.getByRole('row')).toHaveCount(4);
        await expect(table).toContainText('0,000 TND');
        for (const name of ['Dépenses par catégorie — valeurs exactes', 'Transactions par personne — valeurs exactes']) {
            await expect(admin.getByRole('table', { name })).toContainText('9,009 TND');
        }
        await admin.evaluate(() => window.scrollTo(0, 0));
        await expect.poll(() => admin.evaluate(() => window.scrollY)).toBe(0);
        await admin.screenshot({ path: `.artifacts/statistics-${info.project.name}.png`, fullPage: true });
        expect(await admin.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(admin.viewportSize()!.width);
        await admin.reload();
        await expectSummary(admin, '9,009 TND', 2);
        await admin.goto('/transactions');
        await admin.goto('/');
        await expectSummary(admin, '9,009 TND', 2);
        dialog = await openPeriod(viewer, 'Personnalisée');
        await dialog.getByLabel('Du', { exact: false }).fill(`${pastYear}-01-01`);
        await dialog.getByLabel('Au', { exact: false }).fill(`${pastYear}-03-31`);
        await dialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(viewer, '9,009 TND', 2);
        dialog = await openPeriod(admin, 'Personnalisée');
        await dialog.getByLabel('Du', { exact: false }).fill(`${pastYear}-02-01`);
        await dialog.getByLabel('Au', { exact: false }).fill(`${pastYear}-02-28`);
        await dialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(admin, '0,000 TND', 0);
        await expect(admin.getByText('Aucune transaction sur cette période', { exact: true })).toHaveCount(4);
        dialog = await openPeriod(admin, 'Personnalisée');
        await dialog.getByLabel('Du', { exact: false }).fill(`${pastYear}-01-01`);
        await dialog.getByLabel('Au', { exact: false }).fill(`${pastYear}-03-31`);
        await dialog.getByRole('button', { name: 'Appliquer', exact: true }).click();
        await expectSummary(admin, '9,009 TND', 2);
        await timeline.scrollIntoViewIfNeeded();
        await expect.poll(() => timeline.evaluate(element => (window as any).Alpine.$data(element).getChart()?.data.datasets[1].data)).toEqual(['4004', '0', '5005']);
        records.push(await createTransaction(admin, `${pastYear}-01-01`, '10,000', true));
        await admin.goto('/');
        await expectSummary(admin, '9,009 TND', 3);
        await expect(admin.getByTestId('statistics-revenue')).toContainText('10,000 TND');
        await expect(admin.getByTestId('statistics-net')).toContainText('0,991 TND');
        await expect(admin.getByRole('table', { name: 'Recettes par catégorie — valeurs exactes' })).toContainText('10,000 TND');
        await timeline.scrollIntoViewIfNeeded();
        await expect.poll(() => timeline.evaluate(element => (window as any).Alpine.$data(element).getChart()?.data.datasets[0].data)).toEqual(['10000', '0', '0']);
        await admin.evaluate(() => window.scrollTo(0, 0));
        await expect.poll(() => admin.evaluate(() => window.scrollY)).toBe(0);
        await admin.screenshot({ path: `.artifacts/transactions-statistics-${info.project.name}.png`, fullPage: true });
        expect(errors).toEqual([]);
    } finally {
        // Preserve isolation for subsequent desktop/mobile cases sharing the disposable server.
        for (const path of records) {
            await admin.goto(path);
            await admin.getByRole('button', { name: 'Supprimer', exact: true }).click();
            await admin.getByRole('alertdialog').getByRole('button', { name: 'Supprimer', exact: true }).click();
            await expect(admin).toHaveURL(/\/transactions$/);
        }
        await viewerContext.close();
    }
});
