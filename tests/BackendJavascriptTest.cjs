const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const pluginRoot = path.resolve(__dirname, '..');
const sortingScript = fs.readFileSync(path.join(pluginRoot, 'assets/backend/backend.js'), 'utf8');
const sortableScript = fs.readFileSync(path.join(pluginRoot, 'assets/backend/sortable.js'), 'utf8');

function createDom(t, html = '') {
    const dom = new JSDOM(html, { runScripts: 'outside-only' });
    t.after(() => dom.window.close());
    assert.equal(dom.window.jQuery, undefined);
    assert.equal(dom.window.$, undefined);
    return dom;
}

function evaluateSorting(dom, readyState = 'complete') {
    Object.defineProperty(dom.window.document, 'readyState', { value: readyState });
    dom.window.eval(sortingScript);
}

const sortingTable = `
    <table class="data"><tbody>
        <tr><td><div class="drag-handle"><input name="rcd[]" type="hidden" value="1"></div></td></tr>
        <tr><td><div class="drag-handle"><input name="rcd[]" type="hidden" value="2"></div></td></tr>
    </tbody></table>`;

test('sorting waits for DOM readiness and initializes only once', (t) => {
    const dom = createDom(t, sortingTable);
    const calls = [];
    dom.window.Sortable = { create: (...args) => calls.push(args) };
    evaluateSorting(dom, 'loading');
    assert.equal(calls.length, 0);

    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], dom.window.document.querySelector('tbody'));
    assert.equal(calls[0][1].handle, '.drag-handle');
    assert.equal(calls[0][1].animation, 150);
});

test('sorting initializes immediately when the DOM is already ready', (t) => {
    const dom = createDom(t, sortingTable);
    let initialized = false;
    dom.window.Sortable = { create: () => { initialized = true; } };
    evaluateSorting(dom);
    assert.equal(initialized, true);
});

test('sorting safely skips pages without Sortable or a sortable table', (t) => {
    const dom = createDom(t, '<div class="drag-handle"></div>');
    assert.doesNotThrow(() => evaluateSorting(dom));

    dom.window.Sortable = { create: () => assert.fail('No sortable table exists') };
    assert.doesNotThrow(() => evaluateSorting(dom));
    dom.window.document.body.innerHTML = '';
    assert.doesNotThrow(() => evaluateSorting(dom));
});

test('sorting sends cloned inputs in their new order through native AJAX', (t) => {
    const dom = createDom(t, sortingTable);
    dom.window.eval(sortableScript);
    const createSortable = dom.window.Sortable.create;
    let sortable;
    dom.window.Sortable.create = (...args) => {
        sortable = createSortable(...args);
        return sortable;
    };
    const requests = [];
    dom.window.Snowboard = { request: (...args) => requests.push(args) };
    evaluateSorting(dom);

    const tbody = dom.window.document.querySelector('tbody');
    tbody.insertBefore(tbody.lastElementChild, tbody.firstElementChild);
    sortable.options.onEnd({ from: tbody });

    assert.equal(requests.length, 1);
    const [form, handler, options] = requests[0];
    assert.equal(handler, 'onReorderRelation');
    assert.equal(form.tagName, 'FORM');
    assert.equal(form.style.display, 'none');
    assert.deepEqual(Array.from(new dom.window.FormData(form).getAll('rcd[]')), ['2', '1']);
    assert.equal(tbody.querySelectorAll('input').length, 2);
    assert.notEqual(form.querySelector('input'), tbody.querySelector('input'));

    dom.window.document.body.appendChild(form);
    options.complete();
    assert.equal(form.isConnected, false);
    assert.equal(tbody.querySelectorAll('input').length, 2);
});

for (const controller of ['cookiegroups', 'logs']) {
    const template = fs.readFileSync(
        path.join(pluginRoot, 'controllers', controller, '_list_toolbar.htm'),
        'utf8',
    );

    function createToolbar(t) {
        const dom = createDom(t, `
            ${template.replace(/<\?=[\s\S]*?\?>/g, 'translated text')}
            <div class="control-list"><table>
                <thead><tr><th class="list-checkbox"><input type="checkbox" value="all" checked></th></tr></thead>
                <tbody>
                    <tr><td class="list-checkbox"><input type="checkbox" value="11" checked></td></tr>
                    <tr><td class="list-checkbox"><input type="checkbox" value="22"></td></tr>
                    <tr><td class="list-checkbox"><input type="checkbox" value="33" checked></td></tr>
                    <tr><td><input type="checkbox" value="unrelated" checked></td></tr>
                </tbody>
            </table></div>
            <input type="checkbox" value="outside" checked>
        `);
        const button = dom.window.document.querySelector('button');
        // The CMS trigger enables the button when list rows are selected.
        button.disabled = false;
        button.onclick = dom.window.Function('event', button.getAttribute('onclick'));
        return { dom, button };
    }

    test(`${controller} delete sends only selected row IDs and disables after success`, (t) => {
        const { dom, button } = createToolbar(t);
        const requests = [];
        dom.window.Snowboard = { request: (...args) => requests.push(args) };
        button.click();

        assert.equal(requests.length, 1);
        const [element, handler, options] = requests[0];
        assert.equal(element, button);
        assert.equal(handler, 'onDelete');
        assert.deepEqual(Array.from(options.data.checked), ['11', '33']);
        assert.equal(options.confirm, button.dataset.requestConfirm);
        assert.equal(options.loading, true);
        assert.equal(button.type, 'button');
        assert.equal(button.hasAttribute('data-request'), false);
        assert.equal(button.disabled, false);

        options.success();
        assert.equal(button.disabled, true);
    });

    test(`${controller} delete re-reads selection on every click`, (t) => {
        const { dom, button } = createToolbar(t);
        const selections = [];
        // Do not invoke success: this also models a cancelled or failed request.
        dom.window.Snowboard = {
            request: (element, handler, options) => selections.push(Array.from(options.data.checked)),
        };
        button.click();
        dom.window.document.querySelectorAll('.control-list tbody input').forEach((input) => {
            input.checked = input.value === '22';
        });
        button.click();

        assert.deepEqual(selections, [['11', '33'], ['22']]);
        assert.equal(button.disabled, false);
    });
}
