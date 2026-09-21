// NODE_PATH=/path/to/node_modules node tests/restore-precheck-ui.cjs
// Requires jsdom, jquery and PHP; renders the actual OCM_Admin_Page template.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const html = require('node:child_process').execFileSync('php', [path.join(__dirname, 'render-admin-fixture.php')], {encoding:'utf8'});
const source = fs.readFileSync(path.join(__dirname, '../js/admin-script.js'), 'utf8');
const jquery = fs.readFileSync(require.resolve('jquery'), 'utf8');

async function setup() {
    const dom = new JSDOM(html, { url: 'https://destination.example/wp-admin/tools.php', runScripts: 'outside-only' });
    const w = dom.window;
    w.eval(jquery);
    const $ = w.jQuery;
    w.siteData = { ajaxurl: '/admin-ajax.php', prepareRestoreNonce: 'prepare', restoreNonce: 'restore', timeout: 30, otherPluginsActive: 0 };
    const requests = [];
    $.post = (url, data) => {
        const response = $.Deferred();
        requests.push({ url, data, response });
        return response.promise();
    };
    $.ajax = () => {};
    w.eval(source);
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
    await new Promise(resolve => setTimeout(resolve, 20));
    $('button[data-ocm-tab="restore"]').trigger('click');
    $('#ocm_restore_code').val('ABCD-EFGH').trigger('input');
    return { dom, $, requests };
}
const data = () => ({
    token: 'a'.repeat(32), legacy: false, omitted: 0,
    candidates: [
        {id:'temp',host:'old.temp.domains',class:'suggest',example:'https://old.temp.domains/~owner/a.jpg',preview:'https://destination.example/a.jpg'},
        {id:'external',host:'youtube.com',class:'leave_alone',example:'https://youtube.com/watch',preview:'https://destination.example/watch'}
    ]
});
(async () => {
    let t = await setup();
    t.$('#ocm-btn-restore').trigger('click');
    assert.equal(t.requests.length, 1);
    assert.equal(t.requests[0].data.action, 'ocm_prepare_restore');
    assert.equal(t.$('#ocm-pg-pct').text(), '0%');
    t.requests[0].response.resolve({success:true,data:data()});
    assert.equal(t.$('#ocm-restore-precheck input').length, 2);
    assert.equal(t.$('#ocm-restore-precheck input:checked').length, 0);
    assert.equal(t.$('#ocm-restore-precheck details').prop('open'), false);
    assert.equal(t.dom.window.document.activeElement.id, 'ocm-precheck-title');
    t.$('input[value="temp"]').prop('checked', true).trigger('change');
    t.$('#ocm-precheck-start').trigger('click');
    assert.equal(t.requests.length, 2);
    assert.equal(t.requests[1].data.domain_ids.join(','), 'temp');
    assert.equal(t.requests[1].data.precheck_token, 'a'.repeat(32));
    assert.ok(t.requests[1].url.includes('restore_code=ABCDEFGH'));
    t.requests[1].response.reject({responseJSON:{success:false,data:{message:'The check expired.'}}});
    assert.ok(t.$('#ocm-restore-precheck').text().includes('The check expired.'));
    assert.equal(t.$('#ocm-btn-restore').prop('disabled'), false);
    t.dom.window.close();

    t = await setup();
    t.$('#ocm-btn-restore').trigger('click');
    t.$('#ocm_restore_code').val('WXYZ-1234').trigger('input');
    t.requests[0].response.resolve({success:true,data:data()});
    assert.equal(t.$('#ocm-precheck-start').length, 0, 'stale response is ignored');
    t.$('#ocm-btn-restore').trigger('click');
    t.requests[1].response.resolve({success:true,data:data()});
    t.$('input[data-ocm-exclude="db"]').prop('checked',true).trigger('change');
    assert.equal(t.$('#ocm-precheck-start').length, 0, 'changing sections clears review');
    t.dom.window.close();

    t = await setup();
    t.$('#ocm-btn-restore').trigger('click');
    t.requests[0].response.resolve({success:true,data:{token:'b'.repeat(32),legacy:false,omitted:0,candidates:[]}});
    assert.equal(t.requests.length, 2, 'empty inventory adds no confirmation step');
    assert.equal(t.requests[1].data.domain_ids.length, 0);
    t.dom.window.close();

    t = await setup();
    t.$('#ocm-btn-restore').trigger('click');
    const unsafe = data();
    unsafe.candidates[0].example = '<img src=x onerror=alert(1)>';
    t.requests[0].response.resolve({success:true,data:unsafe});
    assert.equal(t.$('#ocm-restore-precheck img').length, 0, 'inventory is escaped');
    t.$('#ocm-precheck-back').trigger('click');
    assert.equal(t.requests.length, 1, 'back never starts restore');
    assert.equal(t.dom.window.document.activeElement.id, 'ocm-btn-restore');
    t.dom.window.close();
    console.log('Restore preparation UI checks passed');
})().catch(error => { console.error(error); process.exit(1); });
