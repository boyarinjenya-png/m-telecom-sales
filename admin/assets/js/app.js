(() => {
  const BASE = String(window.APP_BASE || '').replace(/\/$/, '');
  const state = { csrf: sessionStorage.getItem('mts_csrf'), me: null, catalog: null };
  const $ = (id) => document.getElementById(id);
  const url = (path) => BASE + path;
  const navItems = [
    ['overview', 'Обзор'], ['managers', 'Менеджеры'], ['clients', 'Клиенты'],
    ['calls', 'Звонки'], ['whatsapp', 'WhatsApp'], ['tasks', 'Задачи'],
    ['map', 'Карта'], ['applications', 'Заявки'], ['connections', 'Подключения'],
    ['reports', 'Аналитика'], ['audit', 'Журнал действий'], ['settings', 'Настройки']
  ];

  async function api(path, opts = {}) {
    const headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
    if (!(opts.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    if (state.csrf) headers['X-CSRF-Token'] = state.csrf;
    const res = await fetch(url(path), Object.assign({}, opts, { headers, credentials: 'same-origin' }));
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      const data = await res.json();
      if (!res.ok || data.ok === false) {
        const err = new Error(data.error || 'Ошибка запроса');
        err.payload = data;
        throw err;
      }
      return data;
    }
    if (!res.ok) throw new Error('Ошибка запроса');
    return res;
  }

  function qs(extra = {}) {
    const p = new URLSearchParams();
    p.set('period', $('period').value);
    if ($('period').value === 'custom') {
      if ($('from').value) p.set('from', $('from').value + ' 00:00:00');
      if ($('to').value) p.set('to', $('to').value + ' 23:59:59');
    }
    Object.entries(extra).forEach(([k, v]) => { if (v !== undefined && v !== '') p.set(k, v); });
    return p.toString();
  }

  function fmtSec(s) {
    s = Number(s || 0);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    if (h) return `${h}ч ${m}м`;
    if (m) return `${m}м ${sec}с`;
    return `${sec}с`;
  }
  function durationCell(i) {
    const ds = i.data_source || '';
    if (ds === 'system_verified' || i.duration_is_actual) {
      return i.is_talk ? fmtSec(i.talk_duration_sec) : 'попытка / короткое соединение';
    }
    if (ds === 'manual') {
      const sec = Number(i.talk_duration_sec || i.duration_sec || 0);
      return (sec > 0 ? fmtSec(sec) : '—') + ' · вручную';
    }
    return 'не подтверждено системой';
  }
  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function kpis(map) {
    return `<div class="kpis">${Object.entries(map).map(([l, n]) => `<div class="kpi"><div class="n">${esc(n)}</div><div class="l">${esc(l)}</div></div>`).join('')}</div>`;
  }
  function table(headers, rows) {
    if (!rows.length) return '<p class="muted">Нет данных за выбранный период.</p>';
    return `<div class="table-wrap"><table><thead><tr>${headers.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
  }
  function statusBadge(st, label) {
    const danger = st === 'rejected' ? 'danger' : (st === 'connected' ? 'ok' : '');
    return `<span class="badge ${danger}">${esc(label || st)}</span>`;
  }

  function renderNav(active) {
    $('nav').innerHTML = navItems.map(([id, title]) => `<a href="#/${id}" class="${id === active ? 'active' : ''}">${title}</a>`).join('');
  }

  async function showApp() {
    $('login-screen').hidden = true;
    $('app').hidden = false;
    $('me-name').textContent = state.me.full_name;
    if (!state.catalog) {
      const cat = await api('/api/catalog');
      state.catalog = cat.statuses ? cat : null;
    }
    route();
  }

  async function boot() {
    $('nav').innerHTML = navItems.map(([id, title]) => `<a href="#/${id}">${title}</a>`).join('');
    try {
      const me = await api('/api/auth/me');
      state.me = me.user;
      state.csrf = me.csrf || state.csrf;
      if (state.csrf) sessionStorage.setItem('mts_csrf', state.csrf);
      if (state.me.role !== 'admin') throw new Error('Кабинет только для руководителя');
      await showApp();
    } catch (e) {
      sessionStorage.removeItem('mts_csrf');
      state.csrf = null;
    }
  }

  $('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('login-error').hidden = true;
    const fd = new FormData(e.target);
    try {
      const data = await api('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ login: fd.get('login'), password: fd.get('password'), platform: 'web-admin' })
      });
      state.csrf = data.csrf;
      state.me = data.user;
      if (data.csrf) sessionStorage.setItem('mts_csrf', data.csrf);
      if (data.user.role !== 'admin') throw new Error('Кабинет доступен только руководителю');
      await showApp();
    } catch (err) {
      $('login-error').hidden = false;
      $('login-error').textContent = err.message;
    }
  });

  $('logout-btn').addEventListener('click', async () => {
    try { await api('/api/auth/logout', { method: 'POST', body: '{}' }); } catch (e) {}
    sessionStorage.removeItem('mts_csrf');
    location.reload();
  });
  function setMenu(open) {
    document.querySelector('.sidebar').classList.toggle('open', open);
    const scrim = $('nav-scrim');
    if (scrim) scrim.hidden = !open;
  }
  $('menu-btn').addEventListener('click', () => {
    setMenu(!document.querySelector('.sidebar').classList.contains('open'));
  });
  const navScrim = $('nav-scrim');
  if (navScrim) navScrim.addEventListener('click', () => setMenu(false));
  $('period').addEventListener('change', () => {
    const custom = $('period').value === 'custom';
    $('from').hidden = $('to').hidden = !custom;
    route();
  });
  $('from').addEventListener('change', route);
  $('to').addEventListener('change', route);
  window.addEventListener('hashchange', route);

  async function route() {
    if (!state.me) return;
    const page = (location.hash.replace('#/', '') || 'overview').split('?')[0];
    const title = (navItems.find((x) => x[0] === page) || ['overview', 'Обзор'])[1];
    $('page-title').textContent = title;
    renderNav(page);
    const root = $('content');
    root.innerHTML = '<p class="muted">Загрузка…</p>';
    try {
      if (page === 'overview') await pageOverview(root);
      else if (page === 'managers') await pageManagers(root);
      else if (page === 'clients') await pageClients(root);
      else if (page === 'calls') await pageCalls(root);
      else if (page === 'whatsapp') await pageWhatsapp(root);
      else if (page === 'tasks') await pageTasks(root);
      else if (page === 'map') await pageMap(root);
      else if (page === 'applications') await pageStatusList(root, 'application_received', 'Заявки');
      else if (page === 'connections') await pageStatusList(root, 'connected', 'Подключения');
      else if (page === 'reports' || page === 'analytics') await pageAnalytics(root);
      else if (page === 'audit') await pageAudit(root);
      else if (page === 'settings') await pageSettings(root);
      else root.innerHTML = '<p>Раздел не найден</p>';
    } catch (e) {
      root.innerHTML = `<p class="form-error">${esc(e.message)}</p>`;
    }
  }

  async function pageOverview(root) {
    const d = (await api('/api/analytics/overview?' + qs())).overview;
    const cmp = (await api('/api/analytics/managers?' + qs())).managers;
    root.innerHTML = `<p class="muted">События за выбранный период и отдельно текущий снимок базы.</p>` + kpis({
      'Клиенты сейчас': d.clients_total,
      'Новые за период': d.clients_new,
      'Звонки за период': d.calls_total,
      'Подтверждённые SIM': d.sim_verified_total ?? 0,
      'Неподтверждённые SIM': d.sim_unverified ?? 0,
      'Попытки дозвона': d.sim_verified_attempts ?? d.dial_attempts,
      'Соединения': d.sim_verified_connected ?? 0,
      'Разговоры': d.talks,
      'Подтверждённое время': fmtSec(d.sim_verified_talk_seconds ?? d.talk_seconds),
      'WhatsApp за период': d.whatsapp_total,
      'Стали заинтересованными': d.interested,
      'КП за период': d.offers,
      'Встречи за период': d.meetings,
      'Заявки за период': d.applications,
      'Подключения за период': d.connections,
      'Отказы за период': d.rejected,
      'Просроченные задачи сейчас': d.overdue_tasks
    }) + `<div class="card"><h3>Сравнение менеджеров за период</h3>
      <p class="muted">«В работе» и «Просрочено» — текущий снимок. Остальные колонки — события периода.</p>
      ${renderManagerTable(cmp)}</div>`;
  }

  function renderManagerTable(rows) {
    return table(
      ['Менеджер', 'В работе', 'SIM подтв.', 'SIM неподтв.', 'Разговоры', 'Длительность подтв.', 'WhatsApp', 'Интерес', 'КП', 'Заявки', 'Подкл.', 'Отказы', 'Просрочено'],
      rows.map((r) => `<tr>
        <td>${esc(r.full_name)} ${r.status === 'blocked' ? '<span class="badge danger">блок</span>' : ''}</td>
        <td>${r.clients_in_work}</td><td>${r.sim_verified_total ?? 0}</td><td>${r.sim_unverified ?? 0}</td><td>${r.talks}</td>
        <td>${fmtSec(r.sim_verified_talk_seconds ?? r.talk_seconds)}</td><td>${r.whatsapp}</td><td>${r.interested}</td>
        <td>${r.offers}</td><td>${r.applications}</td><td>${r.connections}</td>
        <td>${r.rejected}</td><td>${r.overdue}</td></tr>`)
    );
  }

  async function pageManagers(root) {
    const users = (await api('/api/users')).users.filter((u) => u.role === 'manager' && u.status !== 'deleted');
    const visibleIds = new Set(users.map((u) => Number(u.id)));
    const cmp = (await api('/api/analytics/managers?' + qs())).managers.filter((u) => visibleIds.has(Number(u.id)));
    root.innerHTML = `<div class="toolbar"><button id="add-manager">Добавить менеджера</button></div>
      <div class="card">${table(['ФИО', 'Логин', 'Телефон', 'Статус', ''], users.map((u) => `<tr>
        <td>${esc(u.full_name)}</td><td>${esc(u.login)}</td><td>${esc(u.phone || '')}</td>
        <td>${u.status === 'blocked' ? '<span class="badge danger">Заблокирован</span>' : '<span class="badge ok">Активен</span>'}</td>
        <td>
          <button class="btn secondary" data-act="${u.status === 'blocked' ? 'unblock' : 'block'}" data-id="${u.id}">${u.status === 'blocked' ? 'Разблокировать' : 'Заблокировать'}</button>
          <button class="btn secondary" data-act="revoke" data-id="${u.id}">Отозвать сессии</button>
          <button class="btn danger" data-act="delete" data-id="${u.id}" data-name="${esc(u.full_name)}">Удалить</button>
        </td></tr>`))}</div>
      <div class="card"><h3>Эффективность</h3>${renderManagerTable(cmp)}</div>`;
    $('add-manager').onclick = () => openForm('Новый менеджер', [
      ['login', 'Логин'], ['full_name', 'ФИО'], ['phone', 'Телефон'], ['password', 'Пароль', 'password']
    ], async (body) => {
      await api('/api/users', { method: 'POST', body: JSON.stringify(body) });
      route();
    });
    root.querySelectorAll('[data-act]').forEach((btn) => btn.onclick = async () => {
      const id = btn.dataset.id, act = btn.dataset.act;
      if (act === 'delete') {
        const name = btn.dataset.name || 'этого менеджера';
        if (!window.confirm(`Удалить менеджера «${name}»? Доступ будет закрыт, активные сессии отозваны. История клиентов, звонков и задач сохранится.`)) return;
      }
      try {
        if (act === 'revoke') await api(`/api/users/${id}/revoke-sessions`, { method: 'POST', body: '{}' });
        else if (act === 'delete') await api(`/api/users/${id}`, { method: 'DELETE' });
        else await api(`/api/users/${id}/${act}`, { method: 'POST', body: '{}' });
        await route();
      } catch (err) {
        window.alert(err.message || 'Не удалось выполнить действие');
      }
    });
  }

  function numOrNull(id) {
    const v = ($(id)?.value || '').trim();
    if (v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
  }
  function rowVal(row, name) {
    return (row.querySelector(`[name="${name}"]`)?.value || '').trim();
  }

  function openCreateClient(users, onSaved) {
    const managers = [
      `<option value="${state.me.id}">${esc(state.me.full_name)} (я)</option>`,
      ...users.map((u) => `<option value="${u.id}">${esc(u.full_name)}</option>`)
    ].join('');
    const html = `<h3>Новый клиент</h3>
      <p class="muted">Сначала выберите тип клиента — от него зависит набор полей.</p>
      <div class="type-pick" role="radiogroup" aria-label="Тип клиента">
        <label class="type-option active"><input type="radio" name="ctype" value="person" checked> Физическое лицо</label>
        <label class="type-option"><input type="radio" name="ctype" value="company"> Юридическое лицо</label>
      </div>
      <p id="c_dup" class="form-warn" hidden></p>
      <p id="c_err" class="form-error" hidden></p>
      <div id="person-fields">
        <label>ФИО<input id="c_full_name" required></label>
        <div class="grid-2">
          <label>Основной телефон<input id="c_phone_primary" type="tel" required></label>
          <label>Дополнительный телефон<input id="c_phone_secondary" type="tel"></label>
        </div>
        <label>Адрес<input id="c_address"></label>
        <div class="grid-2">
          <label>Широта<input id="c_lat" type="number" step="any" placeholder="43.238949"></label>
          <label>Долгота<input id="c_lng" type="number" step="any" placeholder="76.889709"></label>
        </div>
        <label>Текущий провайдер<input id="c_provider"></label>
        <label>Потребность<textarea id="c_need"></textarea></label>
        <label>Комментарий<textarea id="c_comment"></textarea></label>
        <label>Ответственный менеджер<select id="c_manager">${managers}</select></label>
      </div>
      <div id="company-fields" hidden>
        <label>Название компании<input id="co_name"></label>
        <label>БИН<input id="co_bin" inputmode="numeric" maxlength="12" placeholder="12 цифр"></label>
        <div class="grid-2">
          <label>Юридический адрес<input id="co_legal"></label>
          <label>Фактический адрес<input id="co_actual"></label>
        </div>
        <p class="muted" style="font-weight:600;margin:12px 0 6px">Телефоны</p>
        <div class="repeat-block" id="co_phones"></div>
        <p><button type="button" class="btn secondary" id="add-phone">+ Телефон</button></p>
        <p class="muted" style="font-weight:600;margin:12px 0 6px">Контактные лица</p>
        <div class="repeat-block" id="co_contacts"></div>
        <p><button type="button" class="btn secondary" id="add-contact">+ Контактное лицо</button></p>
        <p class="muted" style="font-weight:600;margin:12px 0 6px">Объекты и филиалы</p>
        <div class="repeat-block" id="co_objects"></div>
        <p><button type="button" class="btn secondary" id="add-object">+ Объект / филиал</button></p>
        <label>Текущий провайдер<input id="co_provider"></label>
        <label>Потребность<textarea id="co_need"></textarea></label>
        <label>Комментарий<textarea id="co_comment"></textarea></label>
        <label>Ответственный менеджер<select id="co_manager">${managers}</select></label>
      </div>
      <p><button type="button" id="c_save">Сохранить клиента</button></p>`;
    openHtml('Новый клиент', html, () => {
      const applyType = () => {
        const type = document.querySelector('input[name=ctype]:checked').value;
        $('person-fields').hidden = type !== 'person';
        $('company-fields').hidden = type !== 'company';
        document.querySelectorAll('.type-option').forEach((el) => {
          el.classList.toggle('active', el.querySelector('input').checked);
        });
      };
      document.querySelectorAll('input[name=ctype]').forEach((r) => { r.onchange = applyType; });
      applyType();

      const bindRemove = (row) => {
        row.querySelector('.repeat-remove').onclick = () => row.remove();
      };
      const addPhone = (phone = '', label = '') => {
        const row = document.createElement('div');
        row.className = 'repeat-row cols-2';
        row.innerHTML = `<label>Телефон<input name="co_phone" type="tel" value="${esc(phone)}"></label>
          <label>Подпись<input name="co_phone_label" value="${esc(label)}" placeholder="основной, офис"></label>
          <button type="button" class="btn secondary repeat-remove">Удалить</button>`;
        bindRemove(row);
        $('co_phones').appendChild(row);
      };
      const addContact = () => {
        const row = document.createElement('div');
        row.className = 'repeat-row';
        row.innerHTML = `<label>ФИО<input name="contact_name"></label>
          <label>Должность<input name="contact_pos"></label>
          <label>Телефон<input name="contact_phone" type="tel"></label>
          <button type="button" class="btn secondary repeat-remove">Удалить</button>`;
        bindRemove(row);
        $('co_contacts').appendChild(row);
      };
      const addObject = () => {
        const row = document.createElement('div');
        row.className = 'repeat-row cols-obj';
        row.innerHTML = `<label>Название объекта / филиала<input name="obj_name"></label>
          <label>Адрес<input name="obj_address"></label>
          <label>Широта<input name="obj_lat" type="number" step="any"></label>
          <label>Долгота<input name="obj_lng" type="number" step="any"></label>
          <button type="button" class="btn secondary repeat-remove">Удалить</button>`;
        bindRemove(row);
        $('co_objects').appendChild(row);
      };
      $('add-phone').onclick = () => addPhone();
      $('add-contact').onclick = addContact;
      $('add-object').onclick = addObject;
      addPhone('', 'Основной');
      addContact();
      addObject();

      const showErr = (msg) => {
        $('c_err').hidden = false;
        $('c_err').textContent = msg;
        $('c_dup').hidden = true;
      };
      $('c_save').onclick = async () => {
        $('c_err').hidden = true;
        $('c_dup').hidden = true;
        const type = document.querySelector('input[name=ctype]:checked').value;
        let body;
        if (type === 'person') {
          const fullName = ($('c_full_name').value || '').trim();
          const phone = ($('c_phone_primary').value || '').trim();
          if (!fullName) return showErr('Укажите ФИО');
          if (!phone) return showErr('Укажите основной телефон');
          body = {
            type: 'person',
            full_name: fullName,
            phone_primary: phone,
            phone_secondary: ($('c_phone_secondary').value || '').trim() || null,
            address: ($('c_address').value || '').trim() || null,
            lat: numOrNull('c_lat'),
            lng: numOrNull('c_lng'),
            current_provider: ($('c_provider').value || '').trim() || null,
            need: ($('c_need').value || '').trim() || null,
            comment: ($('c_comment').value || '').trim() || null,
            manager_id: Number($('c_manager').value) || state.me.id
          };
        } else {
          const companyName = ($('co_name').value || '').trim();
          if (!companyName) return showErr('Укажите название компании');
          const phoneRows = Array.from($('co_phones').children).map((row) => ({
            phone: rowVal(row, 'co_phone'),
            label: rowVal(row, 'co_phone_label') || null
          })).filter((p) => p.phone);
          const primary = phoneRows[0] || null;
          const extra = phoneRows.slice(1);
          const contacts = Array.from($('co_contacts').children).map((row) => ({
            full_name: rowVal(row, 'contact_name'),
            position: rowVal(row, 'contact_pos') || null,
            phone: rowVal(row, 'contact_phone') || null
          })).filter((c) => c.full_name);
          const objects = Array.from($('co_objects').children).map((row) => {
            const lat = rowVal(row, 'obj_lat');
            const lng = rowVal(row, 'obj_lng');
            const latN = Number(lat);
            const lngN = Number(lng);
            return {
              name: rowVal(row, 'obj_name'),
              address: rowVal(row, 'obj_address') || null,
              lat: lat === '' || !Number.isFinite(latN) ? null : latN,
              lng: lng === '' || !Number.isFinite(lngN) ? null : lngN
            };
          }).filter((o) => o.name);
          const need = ($('co_need').value || '').trim() || null;
          body = {
            type: 'company',
            company_name: companyName,
            bin: ($('co_bin').value || '').trim() || null,
            legal_address: ($('co_legal').value || '').trim() || null,
            actual_address: ($('co_actual').value || '').trim() || null,
            phone_primary: primary ? primary.phone : null,
            phones: extra,
            contacts,
            objects,
            current_provider: ($('co_provider').value || '').trim() || null,
            need,
            company_needs: need,
            comment: ($('co_comment').value || '').trim() || null,
            manager_id: Number($('co_manager').value) || state.me.id
          };
        }
        const btn = $('c_save');
        btn.disabled = true;
        try {
          await api('/api/clients', { method: 'POST', body: JSON.stringify(body) });
          closeModal();
          await onSaved();
        } catch (err) {
          btn.disabled = false;
          const dups = err.payload && err.payload.duplicates;
          if (Array.isArray(dups) && dups.length) {
            const reason = { phone: 'телефону', bin: 'БИН', company_name: 'названию компании' };
            $('c_dup').hidden = false;
            $('c_dup').innerHTML = 'Найдены совпадения, запись не создана:<ul>' + dups.map((d) => {
              const who = esc(d.company_name || d.full_name || ('#' + d.id));
              const why = reason[d.match_reason] || d.match_reason;
              return `<li>по ${esc(why)}${d.match_value ? ' ' + esc(d.match_value) : ''} — ${who}</li>`;
            }).join('') + '</ul>Измените телефон или БИН. Автоматическое объединение не выполняется.';
            $('c_err').hidden = true;
            return;
          }
          showErr(err.message || 'Не удалось сохранить клиента');
        }
      };
    }, true);
  }

  async function pageClients(root) {
    const users = (await api('/api/users')).users.filter((u) => u.role === 'manager');
    root.innerHTML = `<div class="toolbar">
      <button type="button" id="add-client">+ Добавить клиента</button>
      <input id="q" placeholder="Поиск по ФИО, компании, телефону, БИН">
      <select id="ftype"><option value="">Все типы</option><option value="person">Физлица</option><option value="company">Юрлица</option></select>
      <button type="button" id="reload" class="btn secondary">Найти</button>
    </div><div class="card" id="clist"></div>`;
    const draw = async () => {
      const d = await api('/api/clients?' + qs({ q: $('q').value, type: $('ftype').value, per_page: 100 }));
      $('clist').innerHTML = table(
        ['Клиент', 'Тип', 'Телефон', 'Статус', 'Менеджер', ''],
        d.items.map((c) => `<tr>
          <td><a href="#" data-open="${c.id}">${esc(c.display_name)}</a></td>
          <td>${esc(c.type_label)}</td><td>${esc(c.phone_primary || '')}</td>
          <td>${statusBadge(c.status, c.status_label)}</td>
          <td>${esc(c.manager_name || '')}</td>
          <td><button class="btn secondary" data-assign="${c.id}">Назначить</button></td></tr>`)
      );
      $('clist').querySelectorAll('[data-open]').forEach((a) => a.onclick = (e) => { e.preventDefault(); openClient(a.dataset.open); });
      $('clist').querySelectorAll('[data-assign]').forEach((b) => b.onclick = () => {
        const opts = users.map((u) => `<option value="${u.id}">${esc(u.full_name)}</option>`).join('');
        openHtml('Назначить менеджера', `<label>Менеджер<select id="mid">${opts}</select></label><button id="as">Сохранить</button>`, () => {
          $('as').onclick = async () => {
            await api(`/api/clients/${b.dataset.assign}/assign`, { method: 'POST', body: JSON.stringify({ manager_id: Number($('mid').value) }) });
            closeModal(); route();
          };
        });
      });
    };
    $('add-client').onclick = () => openCreateClient(users, draw);
    $('reload').onclick = draw;
    await draw();
  }

  function digitsPhone(p) { return String(p || '').replace(/\D+/g, ''); }
  function telHref(p) { const d = digitsPhone(p); return d ? 'tel:+' + d : '#'; }
  function waHref(p) { const d = digitsPhone(p); return d ? 'https://wa.me/' + d : '#'; }
  function kv(rows) {
    return `<dl class="info-grid">${rows.filter((r) => r[1] !== '' && r[1] != null).map(([l, v]) =>
      `<dt>${esc(l)}</dt><dd>${v}</dd>`).join('')}</dl>`;
  }
  function emptyMsg(text) { return `<p class="section-empty">${esc(text)}</p>`; }

  async function openClient(id) {
    if (!state.catalog) state.catalog = await api('/api/catalog');
    const cat = state.catalog;
    const c = (await api('/api/clients/' + id)).client;
    const phone = c.phone_primary || '';
    const phones = [c.phone_primary, c.phone_secondary].filter(Boolean)
      .concat((c.phones || []).map((p) => p.phone).filter(Boolean));
    const uniqPhones = [...new Set(phones)];
    const phoneOpts = uniqPhones.map((p) => `<option value="${esc(p)}">${esc(p)}</option>`).join('')
      || `<option value="${esc(phone)}">${esc(phone || 'нет номера')}</option>`;
    const contactResults = cat.contact_results || {
      no_answer: 'Не дозвонился', answered: 'Клиент ответил', interested: 'Заинтересован',
      not_interested: 'Не заинтересован', callback: 'Попросил перезвонить',
      awaiting_offer: 'Ожидает коммерческое предложение', meeting_scheduled: 'Согласовал встречу',
      application_ready: 'Готов оставить заявку', rejected: 'Отказ'
    };
    const contactOpts = Object.entries(contactResults).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
    const resultOpts = Object.entries(cat.results || contactResults).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
    const nextWorkday = (cat.next_workday || '').replace(' ', 'T').slice(0, 16);
    const waKindOpts = Object.entries(cat.whatsapp_kinds || {}).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
    const statusOpts = Object.entries(cat.statuses || {}).map(([k, v]) =>
      `<option value="${k}" ${k === c.status ? 'selected' : ''}>${esc(v)}</option>`).join('');
    const tabs = [
      ['info', 'Основная информация'], ['contacts', 'Контакты'], ['calls', 'Звонки'],
      ['whatsapp', 'WhatsApp'], ['notes', 'Комментарии и встречи'], ['tasks', 'Задачи'],
      ['offers', 'КП'], ['applications', 'Заявки'], ['connections', 'Подключения'], ['history', 'История']
    ];
    const savedTab = state.clientTab || 'info';
    const person = c.type !== 'company';
    const infoHtml = person
      ? kv([
          ['ФИО', esc(c.full_name)], ['Основной телефон', esc(c.phone_primary)],
          ['Дополнительный телефон', esc(c.phone_secondary)],
          ['Адрес', esc(c.address)], ['Провайдер', esc(c.current_provider)],
          ['Потребность', esc(c.need)], ['Комментарий', esc(c.comment)],
          ['Менеджер', esc(c.manager_name)], ['Статус', statusBadge(c.status, c.status_label)],
          ['След. контакт', esc(c.next_contact_at)]
        ])
      : kv([
          ['Компания', esc(c.company_name)], ['БИН', esc(c.bin)],
          ['Основной телефон', esc(c.phone_primary)], ['Дополнительный телефон', esc(c.phone_secondary)],
          ['Юридический адрес', esc(c.legal_address)], ['Фактический адрес', esc(c.actual_address || c.address)],
          ['Потенциальных подключений', c.potential_connections], ['Провайдер', esc(c.current_provider)],
          ['Потребность', esc(c.company_needs || c.need)], ['Комментарий', esc(c.comment)],
          ['Менеджер', esc(c.manager_name)], ['Статус', statusBadge(c.status, c.status_label)],
          ['След. контакт', esc(c.next_contact_at)]
        ]);
    const sectionHtml = (tab) => {
      if (tab === 'info') {
        return infoHtml + `<div class="client-form"><h4>Изменить данные</h4>
          ${person ? `<label>ФИО<input id="e_full_name" value="${esc(c.full_name || '')}"></label>
            <label>Адрес<input id="e_address" value="${esc(c.address || '')}"></label>
            <label>Потребность<input id="e_need" value="${esc(c.need || '')}"></label>`
            : `<label>Компания<input id="e_company_name" value="${esc(c.company_name || '')}"></label>
            <label>БИН<input id="e_bin" value="${esc(c.bin || '')}"></label>
            <label>Юридический адрес<input id="e_legal_address" value="${esc(c.legal_address || '')}"></label>
            <label>Фактический адрес<input id="e_actual_address" value="${esc(c.actual_address || '')}"></label>
            <label>Потенциальных подключений<input id="e_potential_connections" type="number" value="${esc(c.potential_connections ?? '')}"></label>`}
          <label>Основной телефон<input id="e_phone_primary" value="${esc(c.phone_primary || '')}"></label>
          <label>Дополнительный телефон<input id="e_phone_secondary" value="${esc(c.phone_secondary || '')}"></label>
          <label>Провайдер<input id="e_current_provider" value="${esc(c.current_provider || '')}"></label>
          <label>Комментарий<textarea id="e_comment">${esc(c.comment || '')}</textarea></label>
          <label>Статус<select id="st">${statusOpts}</select></label>
          <button type="button" id="save-info">Сохранить</button>
          <button type="button" class="btn secondary" id="save-st">Только статус</button>
        </div>`;
      }
      if (tab === 'contacts') {
        const extraPhones = (c.phones || []).map((p) => `<tr><td>${esc(p.phone)}</td><td>${esc(p.label || '')}</td></tr>`);
        const contacts = (c.contacts || []).map((p) => `<tr><td>${esc(p.full_name)}</td><td>${esc(p.position || '')}</td><td>${esc(p.phone || '')}</td><td>${esc(p.email || '')}</td></tr>`);
        const objects = (c.objects || []).map((o) => `<tr><td>${esc(o.name)}</td><td>${esc(o.address || '')}</td><td>${esc(o.comment || '')}</td></tr>`);
        return `${table(['Телефон', 'Метка'], extraPhones)}
          <h4>Контактные лица</h4>${contacts.length ? table(['ФИО', 'Должность', 'Телефон', 'Email'], contacts) : emptyMsg('Контактные лица не добавлены.')}
          <div class="client-form"><h4>Добавить контакт</h4>
            <label>ФИО<input id="ct_name"></label><label>Должность<input id="ct_pos"></label>
            <label>Телефон<input id="ct_phone"></label><label>Email<input id="ct_email"></label>
            <button type="button" id="add-contact">Сохранить контакт</button></div>
          <h4>Объекты и филиалы</h4>${objects.length ? table(['Название', 'Адрес', 'Комментарий'], objects) : emptyMsg('Объекты не добавлены.')}
          <div class="client-form"><h4>Добавить объект</h4>
            <label>Название<input id="ob_name"></label><label>Адрес<input id="ob_addr"></label>
            <label>Комментарий<input id="ob_comment"></label>
            <button type="button" id="add-object">Сохранить объект</button></div>`;
      }
      if (tab === 'calls') {
        const rows = (c.calls || []).map((i) => `<tr>
          <td>${esc(i.started_at)}</td><td>${esc(i.manager_name || '')}</td><td>${esc(i.phone)}</td>
          <td>${esc(i.direction_label || i.direction || '')}</td>
          <td>${esc(i.channel_label || i.channel || '')}</td>
          <td>${durationCell(i)}</td>
          <td>${esc(i.category_label || '')}</td><td>${esc(i.result_label || i.result || '')}</td>
          <td><span class="badge">${esc(i.data_source_label || i.source_label || '')}</span></td></tr>`);
        return `<p class="muted">Длительность SIM показывается как фактическая только если её подтвердила система. Нажатие «Позвонить» само по себе разговором не является.</p>
          ${table(['Дата', 'Менеджер', 'Телефон', 'Направление', 'Канал', 'Разговор', 'Категория', 'Результат', 'Источник'], rows)}`;
      }
      if (tab === 'whatsapp') {
        const rows = (c.whatsapp || []).map((i) => `<tr>
          <td>${esc(i.created_at)}</td><td>${esc(i.manager_name || '')}</td><td>${esc(i.phone)}</td>
          <td>${esc(i.kind_label || i.kind)}</td><td>${Number(i.offer_sent) ? 'да' : 'нет'}</td>
          <td>${Number(i.duration_sec) > 0 ? fmtSec(i.duration_sec) + ' · вручную' : '—'}</td>
          <td><span class="badge">${esc(i.data_source_label || i.source_label || 'Введено вручную')}</span></td>
          <td>${esc(i.comment || '')}</td></tr>`);
        return `<p class="muted">Только события, которые менеджер зарегистрировал. Переписка WhatsApp не читается. Длительность WhatsApp всегда «Введено вручную».</p>
          ${table(['Дата', 'Менеджер', 'Телефон', 'Тип', 'КП', 'Длительность', 'Источник', 'Комментарий'], rows)}`;
      }
      if (tab === 'notes') {
        const notes = (c.notes || []).map((t) => `<tr><td>${esc(t.created_at)}</td><td>${esc(t.user_name)}</td><td>${esc(t.comment || t.topic || '')}</td></tr>`);
        const meetings = (c.meetings || []).map((t) => `<tr><td>${esc(t.created_at)}</td><td>${esc(t.user_name)}</td><td>${esc(t.topic || t.comment || '')}</td><td>${esc(t.next_contact_at || '')}</td></tr>`);
        return `<h4>Комментарии</h4>${notes.length ? table(['Дата', 'Автор', 'Текст'], notes) : emptyMsg('Комментариев нет.')}
          <h4>Встречи</h4>${meetings.length ? table(['Дата', 'Автор', 'Тема', 'След. контакт'], meetings) : emptyMsg('Встречи не назначены.')}`;
      }
      if (tab === 'tasks') {
        const rows = (c.tasks || []).map((t) => `<tr>
          <td>${esc(t.due_at || '')}</td><td>${esc(t.title)}</td><td>${esc(t.assignee_name || '')}</td>
          <td>${t.overdue ? '<span class="badge danger">Просрочено</span>' : esc(t.status)}</td>
          <td>${t.status === 'open' ? `<button type="button" class="btn secondary" data-done-task="${t.id}">Выполнено</button>` : ''}</td></tr>`);
        return table(['Срок', 'Задача', 'Исполнитель', 'Статус', ''], rows);
      }
      if (tab === 'offers') {
        const rows = (c.offers || []).map((t) => `<tr>
          <td>${esc(t.created_at)}</td><td>${esc(t.user_name || '')}</td>
          <td>${esc(t.offered || t.comment || '')}</td><td>${esc(t.source_label || t.channel || '')}</td></tr>`);
        return table(['Дата', 'Менеджер', 'Предложение', 'Канал'], rows);
      }
      if (tab === 'applications') {
        const rows = (c.applications || []).map((t) => `<tr>
          <td>${esc(t.created_at)}</td><td>${esc(t.user_name)}</td>
          <td>${esc(t.from_label || '—')} → ${esc(t.to_label)}</td></tr>`);
        return `<p class="muted">Заявки — переходы статуса «Заявка получена» по этому клиенту.</p>
          ${rows.length ? table(['Дата', 'Кто', 'Переход'], rows) : emptyMsg('Заявок по этому клиенту нет.')}`;
      }
      if (tab === 'connections') {
        const rows = (c.connections || []).map((t) => `<tr>
          <td>${esc(t.created_at)}</td><td>${esc(t.user_name)}</td>
          <td>${esc(t.from_label || '—')} → ${esc(t.to_label)}</td></tr>`);
        return `<p class="muted">Подключения — переходы в «В процессе подключения» и «Подключён». Статус «Подключён» подтверждает руководитель.</p>
          ${rows.length ? table(['Дата', 'Кто', 'Переход'], rows) : emptyMsg('Подключений по этому клиенту нет.')}`;
      }
      const hist = (c.timeline || []).map((t) => `<li>
        <strong>${esc(t.type_label)}</strong> · ${esc(t.created_at)} · ${esc(t.user_name)}
        <div>${esc(t.result_label || '')} ${t.interest_label ? ' · ' + esc(t.interest_label) : ''}</div>
        <div>${esc(t.topic || t.offered || t.comment || '')}</div>
        <span class="badge">${esc(t.source_label)}</span>
      </li>`);
      const st = (c.status_history || []).map((t) => `<li>
        <strong>Статус</strong> · ${esc(t.created_at)} · ${esc(t.user_name)}
        <div>${esc(t.from_label || '—')} → ${esc(t.to_label)}</div>
      </li>`);
      return `<h4>Взаимодействия</h4><ul class="timeline">${hist.join('') || '<li class="muted">Пока нет взаимодействий</li>'}</ul>
        <h4>Смена статусов</h4><ul class="timeline">${st.join('') || '<li class="muted">История статусов пуста</li>'}</ul>`;
    };

    const html = `<h3>${esc(c.display_name)}</h3>
      <p class="muted">${esc(c.type_label)} · ${statusBadge(c.status, c.status_label)} · ${esc(c.manager_name || 'не назначен')}</p>
      <div class="quick-actions">
        ${phone ? `<button type="button" class="btn" id="qa-call">Позвонить</button>` : ''}
        ${phone ? `<button type="button" class="btn secondary" id="qa-wa">WhatsApp</button>` : ''}
        <div class="more-wrap">
          <button type="button" class="btn secondary" id="qa-more">Дополнительные действия ▾</button>
          <div id="more-menu" class="more-menu" hidden>
            <button type="button" data-act="whatsapp">Зафиксировать WhatsApp</button>
            <button type="button" data-act="note">Комментарий</button>
            <button type="button" data-act="meeting">Встреча</button>
            <button type="button" data-act="task">Задача</button>
            <button type="button" data-act="offer">Коммерческое предложение</button>
            <button type="button" data-act="application">Создать заявку</button>
          </div>
        </div>
      </div>
      <div id="cform"></div>
      <div class="tabs" id="ctabs">${tabs.map(([k, l]) =>
        `<button type="button" class="tab${k === savedTab ? ' active' : ''}" data-ctab="${k}">${esc(l)}</button>`).join('')}</div>
      <div id="csec">${sectionHtml(savedTab)}</div>`;

    const forms = {
      result: `<div class="client-form result-compact"><h4>Результат контакта</h4>
        <p class="muted" id="f_dur_note"></p>
        <label>Результат<select id="f_result">${contactOpts}</select></label>
        <label>Короткий комментарий<textarea id="f_comment"></textarea></label>
        <div id="f_next_wrap">
          <label>Следующий контакт<input id="f_next" type="datetime-local" value="${esc(nextWorkday)}"></label>
          <p class="muted">Можно изменить предложенную дату.</p>
        </div>
        <label id="f_app_wrap" hidden><input id="f_create_app" type="checkbox" style="width:auto"> Создать заявку</label>
        <p class="muted" id="f_follow_hint"></p>
        <button type="button" id="f_save">Сохранить</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`,
      whatsapp: `<div class="client-form result-compact"><h4>WhatsApp</h4>
        <label>Телефон<select id="f_phone">${phoneOpts}</select></label>
        <label>Что произошло<select id="f_kind">
          <option value="message_out">Отправил сообщение</option>
          <option value="message_in">Клиент ответил</option>
          <option value="call">Звонок WhatsApp</option>
          <option value="chat">Открыл чат</option>
          <option value="offer">Отправил КП</option>
        </select></label>
        <label>Результат<select id="f_result"><option value="">Не указан</option>${contactOpts}</select></label>
        <label>Короткий комментарий<textarea id="f_comment"></textarea></label>
        <div id="f_wa_dur_wrap" hidden>
          <label>Длительность, сек<input id="f_talk" type="number" min="0" value="0"></label>
          <p class="muted">Длительность WhatsApp — «Введено вручную», не подтверждается системой.</p>
        </div>
        <div id="f_next_wrap" hidden>
          <label>Следующий контакт<input id="f_next" type="datetime-local" value="${esc(nextWorkday)}"></label>
        </div>
        <label id="f_app_wrap" hidden><input id="f_create_app" type="checkbox" style="width:auto"> Создать заявку</label>
        <p class="muted">Переписка не читается. Фиксируется только то, что указал менеджер.</p>
        <button type="button" id="f_save">Сохранить</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`,
      note: `<div class="client-form"><h4>Комментарий · клиент #${id}</h4>
        <label>Текст<textarea id="f_comment"></textarea></label>
        <button type="button" id="f_save">Сохранить</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`,
      meeting: `<div class="client-form"><h4>Встреча · клиент #${id}</h4>
        <label>Тема<input id="f_topic"></label>
        <label>Комментарий<textarea id="f_comment"></textarea></label>
        <label>Дата и время<input id="f_next" type="datetime-local"></label>
        <button type="button" id="f_save">Сохранить</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`,
      task: `<div class="client-form"><h4>Задача · клиент #${id}</h4>
        <label>Название<input id="f_title"></label>
        <label>Срок<input id="f_due" type="datetime-local"></label>
        <label>Детали<textarea id="f_details"></textarea></label>
        <button type="button" id="f_save">Создать задачу</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`,
      offer: `<div class="client-form"><h4>Коммерческое предложение · клиент #${id}</h4>
        <label>Что предложили<textarea id="f_offered"></textarea></label>
        <label>Комментарий<textarea id="f_comment"></textarea></label>
        <button type="button" id="f_save">Сохранить КП</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`,
      application: `<div class="client-form"><h4>Заявка · клиент #${id}</h4>
        <p class="muted">Будет зафиксирован переход в статус «Заявка получена», если он допустим для текущего этапа.</p>
        <label>Комментарий<textarea id="f_comment"></textarea></label>
        <button type="button" id="f_save">Создать заявку</button>
        <button type="button" class="btn secondary" id="f_cancel">Отмена</button></div>`
    };

    const reload = () => { closeModal(); openClient(id); };
    const needsNext = (r) => ['no_answer', 'callback', 'interested', 'awaiting_offer', 'meeting_scheduled'].includes(r);
    const syncFollowUi = () => {
      const r = $('f_result') ? $('f_result').value : '';
      if ($('f_next_wrap')) $('f_next_wrap').hidden = !needsNext(r);
      if ($('f_app_wrap')) $('f_app_wrap').hidden = r !== 'application_ready';
      if ($('f_follow_hint')) {
        $('f_follow_hint').textContent = r === 'no_answer'
          ? 'Будет создан повторный звонок на следующий рабочий день, если такой задачи ещё нет.'
          : r === 'callback' ? 'Нужна дата и время повторного звонка.'
          : r === 'interested' ? 'Будет задача на подготовку КП.'
          : r === 'awaiting_offer' ? 'Будет задача на отправку КП.'
          : r === 'meeting_scheduled' ? 'Будет задача встречи.'
          : r === 'application_ready' ? 'Заявку можно создать сразу или позже.'
          : r === 'rejected' ? 'Укажите причину отказа в комментарии, если она известна.'
          : '';
      }
      if ($('f_wa_dur_wrap') && $('f_kind')) $('f_wa_dur_wrap').hidden = $('f_kind').value !== 'call';
    };
    const nextValue = () => {
      let next = $('f_next') && !$('f_next_wrap').hidden ? $('f_next').value.replace('T', ' ') : null;
      if (next && next.length === 16) next += ':00';
      return next;
    };
    const showForm = (name, extras = {}) => {
      const box = $('cform');
      box.innerHTML = forms[name] || '';
      if ($('f_cancel')) $('f_cancel').onclick = () => { box.innerHTML = ''; };
      if (name === 'result' && $('f_dur_note')) {
        $('f_dur_note').textContent = extras.durationNote
          || 'Длительность SIM не вводится вручную. Из кабинета запись будет «Не подтверждено системой».';
        box.dataset.callId = extras.callId || '';
      }
      if ($('f_result')) $('f_result').onchange = syncFollowUi;
      if ($('f_kind')) $('f_kind').onchange = syncFollowUi;
      syncFollowUi();
      if (!$('f_save')) return;
      $('f_save').onclick = async () => {
        try {
          if (name === 'result') {
            const body = {
              result: $('f_result').value,
              comment: $('f_comment').value,
              next_contact_at: nextValue(),
              create_application: !!( $('f_create_app') && $('f_create_app').checked )
            };
            if (box.dataset.callId) {
              await api(`/api/calls/${box.dataset.callId}`, { method: 'PATCH', body: JSON.stringify(body) });
            } else {
              await api(`/api/clients/${id}/calls`, { method: 'POST', body: JSON.stringify({
                phone, channel: 'sim', connected: false, talk_duration_sec: 0,
                source: 'manager', data_source: 'unverified',
                ...body, idempotency_key: 'web-call-' + Date.now()
              }) });
            }
          } else if (name === 'whatsapp') {
            await api(`/api/clients/${id}/whatsapp`, { method: 'POST', body: JSON.stringify({
              phone: $('f_phone').value, kind: $('f_kind').value,
              offer_sent: $('f_kind').value === 'offer', comment: $('f_comment').value,
              result: $('f_kind').value === 'offer' ? 'offer_sent' : ($('f_result') ? $('f_result').value || null : null),
              duration_sec: Number($('f_talk') ? $('f_talk').value : 0),
              next_contact_at: nextValue(),
              create_application: !!( $('f_create_app') && $('f_create_app').checked ),
              data_source: 'manual',
              idempotency_key: 'web-wa-' + Date.now()
            }) });
          } else if (name === 'note') {
            await api(`/api/clients/${id}/interactions`, { method: 'POST', body: JSON.stringify({
              type: 'note', comment: $('f_comment').value, idempotency_key: 'web-note-' + Date.now()
            }) });
          } else if (name === 'meeting') {
            let next = $('f_next').value ? $('f_next').value.replace('T', ' ') : null;
            if (next && next.length === 16) next += ':00';
            await api(`/api/clients/${id}/interactions`, { method: 'POST', body: JSON.stringify({
              type: 'meeting', topic: $('f_topic').value, comment: $('f_comment').value,
              result: 'meeting_scheduled', next_contact_at: next,
              idempotency_key: 'web-meet-' + Date.now()
            }) });
          } else if (name === 'task') {
            let due = $('f_due').value ? $('f_due').value.replace('T', ' ') : null;
            if (due && due.length === 16) due += ':00';
            await api(`/api/tasks`, { method: 'POST', body: JSON.stringify({
              client_id: Number(id), title: $('f_title').value, details: $('f_details').value, due_at: due
            }) });
          } else if (name === 'offer') {
            await api(`/api/clients/${id}/interactions`, { method: 'POST', body: JSON.stringify({
              type: 'offer', offered: $('f_offered').value, comment: $('f_comment').value,
              result: 'offer_sent', idempotency_key: 'web-offer-' + Date.now()
            }) });
          } else if (name === 'application') {
            if ($('f_comment').value) {
              await api(`/api/clients/${id}/interactions`, { method: 'POST', body: JSON.stringify({
                type: 'note', comment: $('f_comment').value, result: 'application_received',
                idempotency_key: 'web-app-note-' + Date.now()
              }) });
            }
            await api(`/api/clients/${id}/status`, { method: 'POST', body: JSON.stringify({ status: 'application_received' }) });
          }
          reload();
        } catch (err) {
          box.insertAdjacentHTML('afterbegin', `<p class="form-error">${esc(err.message)}</p>`);
        }
      };
    };

    openHtml('Карточка клиента', html, () => {
      const drawTab = (tab) => {
        state.clientTab = tab;
        document.querySelectorAll('#ctabs .tab').forEach((b) => b.classList.toggle('active', b.dataset.ctab === tab));
        $('csec').innerHTML = sectionHtml(tab);
        bindSection();
      };
      document.querySelectorAll('[data-act]').forEach((b) => { b.onclick = () => {
        if ($('more-menu')) $('more-menu').hidden = true;
        showForm(b.dataset.act);
      }; });
      if ($('qa-more')) $('qa-more').onclick = (e) => {
        e.stopPropagation();
        if ($('more-menu')) $('more-menu').hidden = !$('more-menu').hidden;
      };
      if ($('qa-call')) $('qa-call').onclick = async () => {
        try {
          const res = await api(`/api/clients/${id}/calls`, { method: 'POST', body: JSON.stringify({
            phone, channel: 'sim', connected: false, talk_duration_sec: 0, duration_sec: 0,
            result: 'no_answer', source: 'manager', data_source: 'unverified', attempt: true,
            comment: 'Исходящий вызов инициирован',
            idempotency_key: 'web-dial-' + Date.now()
          }) });
          if (phone) window.location.href = telHref(phone);
          showForm('result', {
            callId: res.call && res.call.id,
            durationNote: 'Длительность из кабинета не подтверждается системой. Нажатие «Позвонить» само по себе не считается разговором.'
          });
        } catch (err) {
          $('cform').innerHTML = `<p class="form-error">${esc(err.message)}</p>`;
        }
      };
      if ($('qa-wa')) $('qa-wa').onclick = () => {
        if (phone) window.open(waHref(phone), '_blank', 'noopener');
        showForm('whatsapp');
      };
      document.querySelectorAll('#ctabs .tab').forEach((b) => { b.onclick = () => drawTab(b.dataset.ctab); });
      const bindSection = () => {
        if ($('save-info')) $('save-info').onclick = async () => {
          const body = {
            phone_primary: $('e_phone_primary').value, phone_secondary: $('e_phone_secondary').value,
            current_provider: $('e_current_provider').value, comment: $('e_comment').value
          };
          if (person) {
            body.full_name = $('e_full_name').value; body.address = $('e_address').value; body.need = $('e_need').value;
          } else {
            body.company_name = $('e_company_name').value; body.bin = $('e_bin').value;
            body.legal_address = $('e_legal_address').value; body.actual_address = $('e_actual_address').value;
            body.potential_connections = $('e_potential_connections').value === '' ? null : Number($('e_potential_connections').value);
          }
          await api(`/api/clients/${id}`, { method: 'PUT', body: JSON.stringify(body) });
          if ($('st').value !== c.status) {
            await api(`/api/clients/${id}/status`, { method: 'POST', body: JSON.stringify({ status: $('st').value }) });
          }
          reload();
        };
        if ($('save-st')) $('save-st').onclick = async () => {
          await api(`/api/clients/${id}/status`, { method: 'POST', body: JSON.stringify({ status: $('st').value }) });
          reload();
        };
        if ($('add-contact')) $('add-contact').onclick = async () => {
          await api(`/api/clients/${id}/contacts`, { method: 'POST', body: JSON.stringify({
            full_name: $('ct_name').value, position: $('ct_pos').value, phone: $('ct_phone').value, email: $('ct_email').value
          }) });
          reload();
        };
        if ($('add-object')) $('add-object').onclick = async () => {
          await api(`/api/clients/${id}/objects`, { method: 'POST', body: JSON.stringify({
            name: $('ob_name').value, address: $('ob_addr').value, comment: $('ob_comment').value
          }) });
          reload();
        };
        document.querySelectorAll('[data-done-task]').forEach((b) => {
          b.onclick = async () => {
            await api(`/api/tasks/${b.dataset.doneTask}`, { method: 'PATCH', body: JSON.stringify({ status: 'done' }) });
            reload();
          };
        });
      };
      bindSection();
    }, true);
  }

  async function pageCalls(root) {
    const items = (await api('/api/calls?' + qs())).items;
    root.innerHTML = `<p class="muted">Подтверждённая длительность берётся только из системных данных Android. Неподтверждённая не показывается как фактическая.</p>
      <div class="card">${table(
      ['Дата', 'Менеджер', 'Клиент', 'Телефон', 'Разговор', 'Категория', 'Результат', 'Источник'],
      items.map((i) => `<tr>
        <td>${esc(i.started_at)}</td><td>${esc(i.manager_name)}</td>
        <td>${esc(i.company_name || i.full_name)}</td><td>${esc(i.phone)}</td>
        <td>${durationCell(i)}</td>
        <td>${esc(i.category_label)}</td><td>${esc(i.result || '')}</td>
        <td><span class="badge">${esc(i.data_source_label || i.source_label)}</span></td></tr>`)
    )}</div>`;
  }

  async function pageWhatsapp(root) {
    const items = (await api('/api/whatsapp')).items;
    root.innerHTML = `<p class="muted">WhatsApp-события вносятся менеджером. Скрытое чтение переписки не используется. Длительность WhatsApp всегда «Введено вручную».</p>
      <div class="card">${table(
        ['Дата', 'Менеджер', 'Клиент', 'Тип', 'КП', 'Длительность', 'Источник'],
        items.map((i) => `<tr>
          <td>${esc(i.created_at)}</td><td>${esc(i.manager_name)}</td>
          <td>${esc(i.company_name || i.full_name)}</td>
          <td>${esc(i.kind_label)}</td><td>${Number(i.offer_sent) ? 'да' : 'нет'}</td>
          <td>${Number(i.duration_sec) > 0 ? fmtSec(i.duration_sec) + ' · вручную' : '—'}</td>
          <td><span class="badge">${esc(i.data_source_label || i.source_label)}</span></td></tr>`)
    )}</div>`;
  }

  async function pageTasks(root) {
    const users = (await api('/api/users')).users.filter((u) => u.role === 'manager');
    const wrap = document.createElement('div');
    root.innerHTML = '';
    root.appendChild(wrap);
    const draw = async () => {
      const bucket = $('tbucket') ? $('tbucket').value : '';
      const assigned = $('tuser') ? $('tuser').value : '';
      const items = (await api('/api/tasks?' + new URLSearchParams({ bucket, assigned_to: assigned }))).items;
      wrap.innerHTML = `<div class="toolbar">
        <select id="tbucket"><option value="">Все</option><option value="today">Сегодня</option>
          <option value="overdue">Просроченные</option><option value="upcoming">Будущие</option>
          <option value="done">Выполненные</option></select>
        <select id="tuser"><option value="">Все менеджеры</option>${users.map((u) => `<option value="${u.id}">${esc(u.full_name)}</option>`).join('')}</select>
      </div><div class="card">${table(
        ['Срок', 'Задача', 'Клиент', 'Менеджер', 'Статус'],
        items.map((t) => `<tr>
          <td>${esc(t.due_at || '')}</td><td>${esc(t.title)}</td>
          <td>${esc(t.client_display || '')}</td><td>${esc(t.assignee_name)}</td>
          <td>${t.overdue ? '<span class="badge danger">Просрочено</span>' : esc(t.status)}</td></tr>`)
      )}</div>`;
      $('tbucket').value = bucket; $('tuser').value = assigned;
      $('tbucket').onchange = $('tuser').onchange = draw;
    };
    await draw();
  }

  async function pageMap(root) {
    const data = await api('/api/map/markers?' + qs());
    root.innerHTML = `<div class="toolbar">
      <select id="mtype"><option value="">Все типы</option><option value="person">Физлица</option><option value="company">Юрлица</option></select>
      <button id="mreload">Фильтр</button>
    </div><div id="map"></div>`;
    const draw = async () => {
      const d = await api('/api/map/markers?' + qs({ type: $('mtype').value }));
      const map = L.map('map').setView([43.238, 76.945], 11);
      L.tileLayer(d.tile_url || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: d.attribution || '© OSM' }).addTo(map);
      d.markers.forEach((m) => {
        L.marker([m.lat, m.lng]).addTo(map).bindPopup(
          `<strong>${esc(m.title)}</strong><br>${esc(m.status_label)}<br>${esc(m.address || '')}<br><a href="#" data-open="${m.client_id}">Карточка</a>`
        );
      });
      if (d.markers.length) map.fitBounds(d.markers.map((m) => [m.lat, m.lng]), { padding: [30, 30] });
    };
    $('mreload').onclick = () => { $('content').querySelector('#map')?.remove(); route(); };
    await draw();
  }

  async function pageStatusList(root, status, title) {
    const ov = (await api('/api/analytics/overview?' + qs())).overview;
    const d = await api('/api/clients?' + qs({ status_event: status, per_page: 200 }));
    const periodN = (ov.by_status_period || {})[status] || 0;
    const nowN = (ov.by_status || {})[status] || 0;
    root.innerHTML = `<p class="muted">За выбранный период переходов: ${periodN}. Сейчас в этом статусе: ${nowN}.</p>
      <div class="card"><h3>${esc(title)} за период</h3>${table(
      ['Клиент', 'Телефон', 'Адрес', 'Менеджер', 'Текущий статус'],
      d.items.map((c) => `<tr><td><a href="#" data-open="${c.id}">${esc(c.display_name)}</a></td>
        <td>${esc(c.phone_primary || '')}</td><td>${esc(c.address || '')}</td>
        <td>${esc(c.manager_name || '')}</td><td>${statusBadge(c.status, c.status_label)}</td></tr>`)
    )}</div>`;
    root.querySelectorAll('[data-open]').forEach((a) => a.onclick = (e) => { e.preventDefault(); openClient(a.dataset.open); });
  }

  function aqs(extra = {}) {
    const mid = $('amanager') ? $('amanager').value : '';
    const grain = $('agrain') ? $('agrain').value : '';
    return qs(Object.assign({ manager_id: mid, grain }, extra));
  }
  function convHtml(c) {
    if (!c || c.value === null || c.value === undefined) {
      return `<span class="muted">${esc(c && c.label ? c.label : 'Недостаточно данных')}</span>`;
    }
    return `<strong>${esc(c.label)}</strong> <span class="muted">(${c.numerator}/${c.denominator})</span>`;
  }
  function lineChart(points, color) {
    const w = 720, h = 190, pad = 28;
    const arr = points && points.length ? points : [{ bucket: '—', n: 0 }];
    const max = Math.max(1, ...arr.map((p) => Number(p.n) || 0));
    const n = Math.max(1, arr.length - 1);
    const xy = arr.map((pt, i) => {
      const x = pad + (i / n) * (w - 2 * pad);
      const y = h - pad - ((Number(pt.n) || 0) / max) * (h - 2 * pad);
      return { x, y, bucket: pt.bucket, n: Number(pt.n) || 0 };
    });
    const pts = xy.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    return `<svg viewBox="0 0 ${w} ${h}" class="chart-svg" role="img">
      <line x1="${pad}" y1="${h - pad}" x2="${w - pad}" y2="${h - pad}" stroke="#E4EAF0"/>
      <polyline fill="none" stroke="${color}" stroke-width="2.5" points="${pts}"></polyline>
      ${xy.map((p) => `<circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="4" fill="${color}"><title>${esc(p.bucket)}: ${p.n}</title></circle>`).join('')}
      <text x="${pad}" y="16" fill="#5B6B7C" font-size="11">${max}</text>
    </svg>`;
  }
  function exportLinks(types) {
    return `<div class="export-list">${types.map(([t, l]) => `<p>${esc(l)} —
      <a href="${url('/api/reports/export?type=' + t + '&format=csv&' + aqs())}" target="_blank">CSV</a> ·
      <a href="${url('/api/reports/export?type=' + t + '&format=xlsx&' + aqs())}" target="_blank">Excel</a></p>`).join('')}</div>`;
  }

  async function pageAnalytics(root) {
    const users = (await api('/api/users')).users.filter((u) => u.role === 'manager');
    const active = users.filter((u) => u.status === 'active');
    const savedTab = state.analyticsTab || 'overview';
    const savedMgr = state.analyticsMgr || '';
    const savedGrain = state.analyticsGrain || 'auto';
    root.innerHTML = `<div class="toolbar">
        <select id="amanager"><option value="">Все менеджеры</option>${users.map((u) =>
          `<option value="${u.id}" ${String(u.id) === String(savedMgr) ? 'selected' : ''}>${esc(u.full_name)}${u.status === 'blocked' ? ' (блок)' : ''}</option>`).join('')}</select>
        <select id="agrain">
          <option value="auto" ${savedGrain === 'auto' ? 'selected' : ''}>Группировка графиков: авто</option>
          <option value="day" ${savedGrain === 'day' ? 'selected' : ''}>По дням</option>
          <option value="week" ${savedGrain === 'week' ? 'selected' : ''}>По неделям</option>
          <option value="month" ${savedGrain === 'month' ? 'selected' : ''}>По месяцам</option>
        </select>
      </div>
      <div class="tabs" id="atabs">
        <button type="button" class="tab" data-tab="overview">Общая аналитика</button>
        <button type="button" class="tab" data-tab="managers">Менеджеры</button>
        <button type="button" class="tab" data-tab="sales">Продажи</button>
      </div>
      <div id="ahost"><p class="muted">Загрузка…</p></div>`;
    const host = $('ahost');
    const setTab = (id) => {
      state.analyticsTab = id;
      root.querySelectorAll('.tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === id));
    };
    const draw = async (tab) => {
      setTab(tab);
      state.analyticsMgr = $('amanager').value;
      state.analyticsGrain = $('agrain').value;
      host.innerHTML = '<p class="muted">Загрузка…</p>';
      try {
        if (tab === 'overview') await drawOverviewAnalytics(host);
        else if (tab === 'managers') await drawManagersAnalytics(host);
        else await drawSalesAnalytics(host);
      } catch (e) {
        host.innerHTML = `<p class="form-error">${esc(e.message)}</p>`;
      }
    };
    $('amanager').onchange = $('agrain').onchange = () => draw(state.analyticsTab || 'overview');
    root.querySelectorAll('.tab').forEach((b) => { b.onclick = () => draw(b.dataset.tab); });
    await draw(savedTab);
  }

  async function drawOverviewAnalytics(host) {
    const [ovp, funp, serp, ctrlp] = await Promise.all([
      api('/api/analytics/overview?' + aqs()),
      api('/api/analytics/funnel?' + aqs()),
      api('/api/analytics/series?' + aqs()),
      api('/api/analytics/control?' + aqs())
    ]);
    const d = ovp.overview, funnel = funp.funnel, series = serp.series, ctrl = ctrlp.control;
    const maxFunnel = Math.max(1, ...funnel.snapshot.map((s) => s.count));
    const kpisSnap = {
      'Всего клиентов в базе': d.clients_total,
      'Клиенты в работе': d.clients_in_work,
      'Просроченные задачи': d.overdue_tasks
    };
    const kpisPeriod = {
      'Новые клиенты': d.clients_new,
      'Подтверждённые SIM': d.sim_verified_total ?? 0,
      'Неподтверждённые SIM': d.sim_unverified ?? 0,
      'Попытки дозвона': d.sim_verified_attempts ?? d.dial_attempts,
      'Состоявшиеся соединения': d.sim_verified_connected ?? 0,
      'Разговоры (подтв.)': d.talks,
      'Подтверждённое время': fmtSec(d.sim_verified_talk_seconds ?? d.talk_seconds),
      'Средняя длительность': fmtSec(d.sim_verified_talk_avg ?? d.talk_seconds_avg),
      'Неподтверждённые взаимодействия': d.unverified_interactions ?? 0,
      'WhatsApp': d.whatsapp_total,
      'Заинтересованные': d.interested,
      'КП отправлены': d.offers,
      'Встречи': d.meetings,
      'Заявки': d.applications,
      'Подключения': d.connections,
      'Отказы': d.rejected
    };
    const chartKeys = [
      ['clients_new', 'Новые клиенты', '#0B3A5B'],
      ['calls', 'Звонки', '#0E4A73'],
      ['talks', 'Разговоры', '#0077A8'],
      ['interested', 'Заинтересованные', '#1F9D55'],
      ['offers', 'КП', '#00A3E0'],
      ['applications', 'Заявки', '#D69E2E'],
      ['connections', 'Подключения', '#1F9D55'],
      ['rejected', 'Отказы', '#E53E3E']
    ];
    const controlItems = [
      ['no_first_contact', 'Без первого контакта'],
      ['no_next_contact', 'Без следующего контакта'],
      ['overdue_next_contact', 'Просроченный повторный контакт'],
      ['stuck_on_stage', 'Долго на одном этапе'],
      ['no_recent_activity', 'Давно без активности']
    ];
    host.innerHTML = `
      <p class="muted">Период ${esc(d.period.from)} — ${esc(d.period.to)}, ${esc(d.period.timezone || 'Asia/Almaty')}. Снимок базы отделён от событий периода.</p>
      <h3 class="block-title">Сейчас в базе</h3>
      ${kpis(kpisSnap)}
      <h3 class="block-title">События за период</h3>
      ${kpis(kpisPeriod)}
      <div class="grid-2">
        <div class="card">
          <h3>Звонки</h3>
          <p>Подтверждённые SIM: <strong>${d.sim_verified_total ?? 0}</strong>. Неподтверждённые SIM: <strong>${d.sim_unverified ?? 0}</strong>.</p>
          <p>Попытки дозвона: <strong>${d.sim_verified_attempts ?? d.dial_attempts}</strong>. Соединения: <strong>${d.sim_verified_connected ?? 0}</strong>. Разговоры: <strong>${d.talks}</strong>.</p>
          <p>Подтверждённое время: <strong>${fmtSec(d.sim_verified_talk_seconds ?? d.talk_seconds)}</strong>. Среднее: <strong>${fmtSec(d.sim_verified_talk_avg ?? d.talk_seconds_avg)}</strong>.</p>
          <p class="muted">${esc((d.notes && d.notes.sim_verified) || '')}</p>
          <p class="muted">${esc((d.notes && d.notes.talks) || '')}</p>
        </div>
        <div class="card">
          <h3>WhatsApp</h3>
          <p>Всего событий: <strong>${d.whatsapp_total}</strong>. Исходящие: ${d.whatsapp_messages}. Ответы: ${d.whatsapp_replies}. Звонки WhatsApp: ${d.whatsapp_calls}. КП: ${d.whatsapp_offers}.</p>
          <p class="muted">${esc((d.notes && d.notes.whatsapp) || '')}</p>
        </div>
      </div>
      <div class="card">
        <h3>Воронка — текущий снимок</h3>
        <p class="muted">${esc(funnel.method)}</p>
        <div class="funnel">${funnel.snapshot.map((s) => {
          const w = Math.max(18, Math.round(100 * s.count / maxFunnel));
          const prev = { processed: 'new_to_processed', interested: 'processed_to_interested', offer_sent: 'interested_to_offer_sent', awaiting: 'offer_sent_to_awaiting', application: 'awaiting_to_application', connected: 'application_to_connected' }[s.key];
          const conv = prev && funnel.step_conversion ? funnel.step_conversion[prev] : null;
          return `<div class="funnel-step" style="width:${w}%"><span>${esc(s.label)}</span><strong>${s.count}</strong>${conv ? `<em>${esc(conv.label)}</em>` : ''}</div>`;
        }).join('')}</div>
        <p class="muted">Проценты справа — исторический переход уникальных клиентов за период, не доля от ширины столбца.</p>
      </div>
      <div class="card">
        <h3>Динамика</h3>
        <p class="muted">Группировка: ${esc(series.grain)}. Наведите на точку, чтобы увидеть значение.</p>
        <div class="chart-grid">${chartKeys.map(([k, l, c]) => `<div class="chart-block"><h4>${esc(l)}</h4>${lineChart((series.series || {})[k] || [], c)}</div>`).join('')}</div>
      </div>
      <div class="card">
        <h3>Контроль работы</h3>
        <div class="kpis">
          <div class="kpi"><div class="n">${ctrl.counts.tasks_done_on_time}</div><div class="l">Задачи вовремя за период</div></div>
          <div class="kpi"><div class="n">${ctrl.counts.tasks_done_late}</div><div class="l">Задачи с опозданием</div></div>
          <div class="kpi"><div class="n">${ctrl.counts.overdue_tasks}</div><div class="l">Просроченные задачи сейчас</div></div>
        </div>
        ${controlItems.map(([key, label]) => {
          const rows = (ctrl.lists[key] || []);
          const n = ctrl.counts[key] || 0;
          return `<h4>${esc(label)} · ${n}</h4>
            ${table(['Клиент', 'Статус', 'Менеджер', ''], rows.map((c) => `<tr>
              <td><a href="#" data-open="${c.id}">${esc(c.display_name)}</a></td>
              <td>${statusBadge(c.status, c.status_label)}</td>
              <td>${esc(c.manager_name || '')}</td>
              <td><button class="btn secondary" data-open="${c.id}">Карточка</button></td></tr>`))}`;
        }).join('')}
        <p class="muted">${esc(ctrl.notes.stuck_on_stage || '')} ${esc(ctrl.notes.no_recent_activity || '')}</p>
      </div>
      <div class="card">
        <h3>Экспорт</h3>
        <p class="muted">Учитываются период в шапке и фильтр менеджера.</p>
        ${exportLinks([
          ['overview', 'Общая аналитика'], ['managers', 'Статистика менеджеров'], ['sales', 'Продажи'],
          ['calls', 'Звонки'], ['applications', 'Заявки'], ['connections', 'Подключения'],
          ['clients', 'Клиенты'], ['whatsapp', 'WhatsApp'], ['rejected', 'Отказы'], ['objects', 'Объекты']
        ])}
      </div>`;
    host.querySelectorAll('[data-open]').forEach((a) => a.onclick = (e) => { e.preventDefault(); openClient(a.dataset.open); });
  }

  async function drawManagersAnalytics(host) {
    const data = await api('/api/analytics/managers?' + aqs());
    const rows = data.managers || [];
    const ranks = data.rankings || {};
    const rankBlock = (title, list) => `<div class="card"><h4>${esc(title)}</h4>${table(
      ['#', 'Менеджер', 'Значение'],
      (list || []).map((r) => `<tr><td>${r.rank ?? '—'}</td><td><a href="#" data-mgr="${r.manager_id}">${esc(r.full_name)}</a></td><td>${esc(r.label)}</td></tr>`)
    )}</div>`;
    host.innerHTML = `<p class="muted">${esc(ranks.basis || '')}</p>
      <div class="card"><h3>Сравнение менеджеров</h3>
      ${table(
        ['Менеджер', 'Закреплено', 'Новые', 'SIM подтв.', 'SIM неподтв.', 'Попытки', 'Соединения', 'Разговоры', 'Время подтв.', 'Среднее', 'WhatsApp', 'Интерес', 'КП', 'Встречи', 'Заявки', 'Подкл.', 'Отказы', 'Проср.', 'Конверсия'],
        rows.map((r) => `<tr data-mgr="${r.manager_id}" class="click-row">
          <td><a href="#" data-mgr="${r.manager_id}">${esc(r.full_name)}</a> ${r.status === 'blocked' ? '<span class="badge danger">блок</span>' : ''}</td>
          <td>${r.assigned_clients}</td><td>${r.clients_new}</td>
          <td>${r.sim_verified_total ?? 0}</td><td>${r.sim_unverified ?? 0}</td>
          <td>${r.dial_attempts}</td><td>${r.sim_verified_connected ?? 0}</td><td>${r.talks}</td>
          <td>${fmtSec(r.sim_verified_talk_seconds ?? r.talk_seconds)}</td><td>${fmtSec(r.talk_seconds_avg)}</td><td>${r.whatsapp}</td>
          <td>${r.interested}</td><td>${r.offers}</td><td>${r.meetings}</td><td>${r.applications}</td>
          <td>${r.connections}</td><td>${r.rejected}</td><td>${r.overdue}</td>
          <td>${convHtml(r.conversion_new_to_connected)}</td></tr>`)
      )}</div>
      <div class="grid-2">
        ${rankBlock('Рейтинг: подключения', ranks.by_connections)}
        ${rankBlock('Рейтинг: заявки', ranks.by_applications)}
        ${rankBlock('Рейтинг: конверсия новые→подключения', ranks.by_conversion)}
        ${rankBlock('Рейтинг: обработанные клиенты', ranks.by_processed)}
      </div>
      <p class="muted">Нажмите менеджера, чтобы открыть общую аналитику только по нему.</p>`;
    host.querySelectorAll('[data-mgr]').forEach((a) => a.onclick = (e) => {
      e.preventDefault();
      $('amanager').value = a.dataset.mgr;
      state.analyticsMgr = a.dataset.mgr;
      state.analyticsTab = 'overview';
      document.querySelector('.tab[data-tab="overview"]').click();
    });
  }

  async function drawSalesAnalytics(host) {
    const sales = (await api('/api/analytics/sales?' + aqs())).sales;
    const s = sales.snapshot || {};
    const e = sales.period_events || {};
    const c = sales.conversion || {};
    host.innerHTML = `
      <p class="muted">${esc(sales.method)}</p>
      <h3 class="block-title">Текущая воронка (снимок базы)</h3>
      ${kpis({
        'Новые / не обработаны': s.new,
        'Заинтересованные': s.interested,
        'КП отправлены': s.offer_sent,
        'Ожидают решения': s.awaiting_decision,
        'Заявки': s.application_received,
        'В подключении': s.connecting,
        'Подключены': s.connected,
        'Отказы': s.rejected
      })}
      <h3 class="block-title">События периода</h3>
      ${kpis({
        'Новые клиенты': e.clients_new,
        'Стали заинтересованными': e.interested,
        'КП': e.offers,
        'Заявки': e.applications,
        'Подключения': e.connections,
        'Отказы': e.rejected
      })}
      <div class="card">
        <h3>Конверсия когорты новых клиентов</h3>
        <p>Из нового в заинтересованного: ${convHtml(c.new_to_interested)}</p>
        <p>Из заинтересованного в заявку: ${convHtml(c.interested_to_application)}</p>
        <p>Из заявки в подключение: ${convHtml(c.application_to_connected)}</p>
        <p>Общая из нового в подключение: ${convHtml(c.new_to_connected)}</p>
        <p class="muted">Когорта: новые ${sales.cohort ? sales.cohort.new : 0}; из них заинтересованных ${sales.cohort ? sales.cohort.interested : 0}, заявок ${sales.cohort ? sales.cohort.application : 0}, подключений ${sales.cohort ? sales.cohort.connected : 0}.</p>
      </div>
      ${exportLinks([['sales', 'Продажи'], ['applications', 'Заявки'], ['connections', 'Подключения'], ['rejected', 'Отказы']])}`;
  }

  async function pageAudit(root) {
    const items = (await api('/api/audit')).items;
    root.innerHTML = `<div class="card">${table(
      ['Время', 'Пользователь', 'Действие', 'Объект', 'ID'],
      items.map((i) => `<tr><td>${esc(i.created_at)}</td><td>${esc(i.full_name || '')}</td>
        <td>${esc(i.action)}</td><td>${esc(i.entity_type || '')}</td><td>${esc(i.entity_id || '')}</td></tr>`)
    )}</div>`;
  }

  async function pageSettings(root) {
    const s = (await api('/api/settings')).settings;
    const ver = await api('/api/app/version');
    root.innerHTML = `<div class="card">
      <label>Название<input id="app_name" value="${esc(s.app_name || '')}"></label>
      <label>Хранение резервных копий, дни<input id="backup_retention_days" value="${esc(s.backup_retention_days || '14')}"></label>
      <p class="muted">Готовность WhatsApp Business Platform: ${s.whatsapp_business_ready === '1' ? 'включена' : 'не подключена (ручная фиксация)'}.</p>
      <button id="save-set">Сохранить</button>
      <h3>Android</h3>
      <p>Текущая версия: ${esc(ver.version_name)} (${esc(ver.version_code)})</p>
      <p class="muted">${esc(ver.changelog || '')}</p>
    </div>`;
    $('save-set').onclick = async () => {
      await api('/api/settings', { method: 'PUT', body: JSON.stringify({
        app_name: $('app_name').value,
        backup_retention_days: $('backup_retention_days').value
      }) });
      route();
    };
  }

  function openForm(title, fields, onSave) {
    const html = `<h3>${esc(title)}</h3>` + fields.map(([n, l, t]) => `<label>${esc(l)}<input id="f_${n}" type="${t || 'text'}"></label>`).join('') + `<button id="fsave">Сохранить</button>`;
    openHtml(title, html, () => {
      $('fsave').onclick = async () => {
        const body = {};
        fields.forEach(([n]) => body[n] = document.getElementById('f_' + n).value);
        await onSave(body);
        closeModal();
      };
    });
  }
  function openHtml(title, html, bind, wide) {
    const m = $('modal');
    m.hidden = false;
    m.innerHTML = `<div class="modal-card${wide ? ' wide' : ''}">${html}<p><button class="btn secondary" id="mclose">Закрыть</button></p></div>`;
    $('mclose').onclick = closeModal;
    m.onclick = (e) => { if (e.target === m) closeModal(); };
    bind && bind();
  }
  function closeModal() { $('modal').hidden = true; $('modal').innerHTML = ''; }

  boot();
})();
