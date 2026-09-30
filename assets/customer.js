(() => {
  'use strict';
  const config = window.TraVisa;
  if (!config) return;
  document.querySelectorAll('.tv-form').forEach(form => {
    const find = s => form.querySelector(s);
    const country = find('.tv-country'), tier = find('.tv-tier'), appointment = find('.tv-appointment');
    const result = find('.tv-result'), status = find('.tv-status'), add = find('.tv-add'), calculate = find('.tv-calculate');
    const catalog = config.catalog;
    let quote = null, revision = 0, busy = false;
    const el = (tag, text, className) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; };
    const money = value => new Intl.NumberFormat('ar-SA', { style: 'currency', currency: config.currency }).format(value / 100);
    function invalidate() { revision++; quote = null; add.disabled = true; result.textContent = 'تغيّرت الاختيارات. اضغط حساب التكلفة لتحديث الملخص.'; status.textContent = ''; }
    const option = (value, text) => { const n = el('option', text); n.value = value; return n; };
    [...new Set(catalog.filter(r => r.kind === 'visa').map(r => r.country))].sort((a,b) => a.localeCompare(b,'ar')).forEach(c => country.append(option(c,c)));
    function quantity(r, destination, label) {
      const wrap = el('label', undefined, 'tv-quantity'); wrap.append(el('span',label));
      const input = el('input'); input.type = 'number'; input.min = '0'; input.max = '100'; input.step = '1'; input.value = '0'; input.dataset.record = r.id; input.setAttribute('aria-label',label); wrap.append(input); destination.append(wrap);
      const details = el('details', undefined, 'tv-service-details');
      details.append(el('summary', `تفاصيل الخدمة — ${label}`));
      details.append(el('p', r.details || 'لم تُضف تفاصيل لهذه الخدمة بعد. تواصل معنا للاستفسار.', 'tv-service-description'));
      destination.append(details);
    }
    function travelers() {
      const box = find('.tv-travelers'); box.replaceChildren();
      const rows = catalog.filter(r => r.kind === 'visa' && r.country === country.value && r.tier === tier.value);
      rows.forEach(r => quantity(r,box,r.category));
      find('.tv-travelers-box').hidden = rows.length === 0;
      const home = tier.value === 'home'; find('.tv-home-note').hidden = !home;
      find('.tv-appointment-label').hidden = home || !country.value;
      appointment.disabled = home || !country.value;
      invalidate();
    }
    function tiers() {
      tier.replaceChildren();
      const available = new Set(catalog.filter(r => r.kind === 'visa' && r.country === country.value).map(r => r.tier));
      Object.entries(config.tiers).forEach(([key,label]) => { if (available.has(key)) tier.append(option(key,label)); });
      tier.disabled = available.size === 0; travelers();
    }
    country.addEventListener('change',tiers); tier.addEventListener('change',travelers);
    catalog.filter(r => r.kind === 'standalone').forEach(r => quantity(r,find('.tv-extras'),r.name));
    if (!find('.tv-extras').children.length) find('.tv-extras').append(el('p','لا توجد خدمات مستقلة متاحة حاليًا.'));
    form.addEventListener('input',invalidate); tiers(); result.textContent = 'اختر الخدمات والمسافرين لعرض السعر.';
    function request() {
      const items = [];
      form.querySelectorAll('[data-record]').forEach(input => {
        const n = Number(input.value);
        if (!Number.isInteger(n) || n < 0 || n > 100) throw new Error('الأعداد من 0 إلى 100، دون كسور.');
        if (n > 0) items.push({id: input.dataset.record, quantity: n});
      });
      if (!items.length) throw new Error('اختر مسافرًا واحدًا أو خدمة مستقلة على الأقل.');
      return {items, appointment: tier.value === 'home' ? 'vip' : appointment.value};
    }
    async function post(action, data, signature) {
      const body = new URLSearchParams({action, nonce:config.nonce, request:JSON.stringify(data)});
      if (signature) body.set('signature',signature);
      const response = await fetch(config.url,{method:'POST',credentials:'same-origin',body});
      const text = await response.text();
      let json; try { json = JSON.parse(text); } catch (_) { throw new Error('تعذر إتمام الطلب. حدّث الصفحة وحاول مجددًا.'); }
      if (!json.success) throw new Error(json.data?.message || 'تعذر إتمام الطلب. حدّث الصفحة وحاول مجددًا.');
      return json.data;
    }
    function summary(q) {
      result.replaceChildren();
      q.lines.forEach(line => {
        const row = el('div',undefined,'tv-line'); row.append(el('span',`${line.name} ${line.category} × ${line.quantity}`),el('strong',money(line.payable + line.discount))); result.append(row);
      });
      if (q.discount) { const off = el('div',undefined,'tv-line'); off.append(el('span','خصم الخدمة'),el('strong',`− ${money(q.discount)}`)); result.append(off); }
      const total = el('div',undefined,'tv-total'); total.append(el('span','قيمة الخدمات بعد الخصم'),el('strong',money(q.total))); result.append(total);
      const info = el('details',undefined,'tv-info'); info.append(el('summary','رسوم معلوماتية خارج المبلغ المدفوع'));
      q.lines.filter(l => l.country).forEach(line => {
        info.append(el('p',`${line.category}: التأشيرة ${line.visa_info === null ? 'غير محددة' : money(line.visa_info)} · الشحن ${line.shipping_info === null ? 'غير محدد' : money(line.shipping_info)}`));
      }); result.append(info);
    }
    form.addEventListener('submit',async event => {
      event.preventDefault(); if (busy) return;
      quote = null; add.disabled = true; const current = revision;
      try {
        const data = request(); busy = true; calculate.disabled = true; status.textContent = 'جارٍ حساب السعر…';
        const q = await post('travisa_quote',data);
        if (current !== revision) return;
        quote = {q,data}; summary(q); add.disabled = false; status.textContent = 'راجع التفاصيل ثم أضف الحجز إلى السلة.';
      } catch (error) { status.textContent = error.message; }
      finally { busy = false; calculate.disabled = false; }
    });
    add.addEventListener('click',async () => {
      if (!quote || busy) return; busy = true; add.disabled = true; calculate.disabled = true;
      // Freeze selections during cart creation so the visible booking matches the submitted booking.
      const fields = [...form.querySelectorAll('input,select')].map(n => [n,n.disabled]); fields.forEach(([n]) => n.disabled = true);
      try { status.textContent = 'جارٍ إضافة الحجز…'; const data = await post('travisa_add',quote.data,quote.q.signature); window.location.assign(data.url); }
      catch (error) { status.textContent = error.message; quote = null; }
      finally { busy = false; calculate.disabled = false; fields.forEach(([n,disabled]) => n.disabled = disabled); }
    });
  });
})();
