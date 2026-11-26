(function () {
  'use strict';

  const apiFetch = window.wp.apiFetch;
  const __ = window.wp.i18n.__;
  const _n = window.wp.i18n._n;
  const sprintf = window.wp.i18n.sprintf;
  const boot = window.wp2vercelBoot || {};
  const root = document.getElementById('wp2vercel-app');
  const API = '/wp2vercel/v1';
  const POLL_MS = 2500;
  const MISSED_ANSWERS_SHOWN = 3;

  const state = {
    tab: 'publish',
    snapshot: null,
    settings: null,
    log: [],
    lastLogId: 0,
    missedAnswers: 0,
    logBuildId: 0,
    busy: false,
    notice: null,
    timer: null,
    readiness: null,
  };

  const STEP_LABELS = {
    setup: __('Preparing', 'wp2vercel'),
    seed: __('Listing content', 'wp2vercel'),
    crawl: __('Saving pages', 'wp2vercel'),
    finalize: __('Writing vercel.json', 'wp2vercel'),
    scan: __('Scanning for leaks', 'wp2vercel'),
    deploy: __('Publishing', 'wp2vercel'),
  };
  const STEPS = Object.keys(STEP_LABELS);
  const FINDING_LABELS = {
    origin_host: __('Private address', 'wp2vercel'),
    sensitive_file: __('Sensitive file', 'wp2vercel'),
  };
  const extensions = { tabs: [], publishActions: [], publishPanels: [], findingsHelp: [], formPagesHelp: [], findingLabels: {}, stepLabels: {} };

  function el(tag, attributes, children) {
    const node = document.createElement(tag);
    Object.entries(attributes || {}).forEach(function ([key, value]) {
      if (value === false || value === null || value === undefined) {
        return;
      }
      if (key === 'class') {
        node.className = value;
      } else if (key === 'text') {
        node.textContent = value;
      } else if (key.startsWith('on')) {
        node.addEventListener(key.slice(2), value);
      } else if (key === 'checked' || key === 'disabled' || key === 'value') {
        node[key] = value;
      } else {
        node.setAttribute(key, value === true ? '' : value);
      }
    });
    [].concat(children || []).forEach(function (child) {
      if (child === null || child === undefined || child === false) {
        return;
      }
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function link(href, label) {
    return el('a', { href: href, target: '_blank', rel: 'noopener noreferrer', text: label || href });
  }

  function setNotice(type, message) {
    state.notice = message ? { type: type, message: message } : null;
    render();
  }

  function absorb(snapshot) {
    const buildId = snapshot.build ? snapshot.build.id : 0;
    if (buildId !== state.logBuildId) {
      state.log = [];
      state.lastLogId = 0;
      state.logBuildId = buildId;
    }
    (snapshot.log || []).forEach(function (entry) {
      const id = Number(entry.id);
      if (id > state.lastLogId) {
        state.log.push(entry);
        state.lastLogId = id;
      }
    });
    if (state.snapshot && state.snapshot.active && !snapshot.active) {
      state.readiness = null;
    }
    state.snapshot = snapshot;
    state.missedAnswers = 0;
  }

  function tryAgain(request) {
    state.missedAnswers += 1;
    if (state.missedAnswers === MISSED_ANSWERS_SHOWN) {
      render();
    }
    window.clearTimeout(state.timer);
    state.timer = window.setTimeout(request, POLL_MS * 2);
  }

  function schedule() {
    window.clearTimeout(state.timer);
    if (!state.snapshot || !state.snapshot.active) {
      return;
    }
    if (state.snapshot.build && state.snapshot.build.status === 'waiting') {
      state.timer = window.setTimeout(watch, POLL_MS * 6);
      return;
    }
    state.timer = window.setTimeout(advance, POLL_MS);
  }

  function advance() {
    apiFetch({ path: API + '/tick?after_log=' + state.lastLogId, method: 'POST' })
      .then(function (snapshot) {
        absorb(snapshot);
        render();
        schedule();
      })
      .catch(function () { tryAgain(advance); });
  }

  function watch() {
    refresh().catch(function () { tryAgain(watch); });
  }

  function refresh() {
    return apiFetch({ path: API + '/status?after_log=' + state.lastLogId }).then(function (snapshot) {
      absorb(snapshot);
      render();
      schedule();
    });
  }

  function start(body) {
    state.busy = true;
    setNotice(null);
    apiFetch({ path: API + '/builds', method: 'POST', data: body })
      .then(function (snapshot) {
        absorb(snapshot);
        state.busy = false;
        render();
        schedule();
      })
      .catch(function (error) {
        state.busy = false;
        setNotice('error', error.message);
      });
  }

  function cancel() {
    apiFetch({ path: API + '/builds/cancel', method: 'POST' })
      .then(function (snapshot) {
        absorb(snapshot);
        render();
      })
      .catch(function (error) { setNotice('error', error.message); });
  }

  function renderProgress(build, counts, idleSeconds) {
    const steps = build.steps || STEPS;
    const index = steps.indexOf(build.step);
    const done = counts ? counts.fetched + counts.redirect + counts.skipped + counts.failed : 0;
    const total = counts ? done + counts.queued : 0;
    return el('div', { class: 'wp2v-progress' }, [
      el('ol', { class: 'wp2v-steps' }, steps.map(function (step, position) {
        const status = position < index ? 'is-done' : position === index ? 'is-current' : '';
        return el('li', { class: status, text: STEP_LABELS[step] || extensions.stepLabels[step] || step });
      })),
      build.waiting
        ? el('p', { class: 'wp2v-muted', text: __('This publish is paused and waiting to be resumed.', 'wp2vercel') })
        : null,
      idleSeconds >= 300
        ? el('div', { class: 'notice inline notice-warning' }, el('p', { text: sprintf(
            __('No progress for %d minutes. The server may be ending the publish at its PHP time or memory limit. It is tried again by itself and stops with a reason after a few more attempts. You can also cancel it now.', 'wp2vercel'),
            Math.floor(idleSeconds / 60)
          ) }))
        : null,
      build.step === 'crawl' && total > 0
        ? el('p', { class: 'wp2v-muted', text: sprintf(__('%1$d of %2$d URLs processed', 'wp2vercel'), done, total) })
        : null,
    ]);
  }

  function renderResult(build, counts) {
    const deployment = build.deployment || {};
    const rows = [
      [__('Result', 'wp2vercel'), build.message || build.status],
      [__('Files', 'wp2vercel'), String(build.files)],
    ];
    if (counts && build.type !== 'restore') {
      rows.push([__('URLs', 'wp2vercel'), sprintf(__('Saved: %1$d · Redirects: %2$d · Failed: %3$d', 'wp2vercel'), counts.fetched, counts.redirect, counts.failed)]);
    }
    const list = el('dl', { class: 'wp2v-facts' }, rows.flatMap(function (row) {
      return [el('dt', { text: row[0] }), el('dd', { text: row[1] })];
    }));
    if (deployment.url) {
      list.appendChild(el('dt', { text: build.target === 'github' ? __('Branch', 'wp2vercel') : __('Deployment', 'wp2vercel') }));
      list.appendChild(el('dd', {}, link(deployment.url)));
    }
    if (deployment.commit_url) {
      list.appendChild(el('dt', { text: __('Commit', 'wp2vercel') }));
      list.appendChild(el('dd', {}, link(deployment.commit_url, deployment.commit.slice(0, 7))));
    }
    return list;
  }

  function renderFindings(build) {
    if (!build.findings_total) {
      return build.status === 'completed'
        ? el('p', { class: 'wp2v-ok', text: __('Leak scan: no reference to the private WordPress address in the published files.', 'wp2vercel') })
        : null;
    }
    return el('div', { class: 'wp2v-findings' }, [
      el('h3', { text: sprintf(_n('Leak scan: %d finding', 'Leak scan: %d findings', build.findings_total, 'wp2vercel'), build.findings_total) }),
      el('p', { class: 'wp2v-muted', text: build.blocked
        ? __('Publishing was stopped because of these findings. Nothing was deployed. Change the pages listed here, then publish again.', 'wp2vercel')
        : __('These published files still mention the private WordPress address, or are file types that should not be public.', 'wp2vercel') }),
    ].concat(extend(extensions.findingsHelp.map(function (renderer) {
      return function (core) { return renderer(core, build); };
    }))).concat([
      el('table', { class: 'widefat striped' }, [
        el('thead', {}, el('tr', {}, [
          el('th', { text: __('Type', 'wp2vercel') }),
          el('th', { text: __('File', 'wp2vercel') }),
          el('th', { text: __('Line', 'wp2vercel') }),
          el('th', { text: __('Context', 'wp2vercel') }),
        ])),
        el('tbody', {}, build.findings.map(function (finding) {
          return el('tr', {}, [
            el('td', { text: extensions.findingLabels[finding.type] || FINDING_LABELS[finding.type] || finding.type }),
            el('td', {}, el('code', { text: finding.file })),
            el('td', { text: finding.line ? String(finding.line) : '' }),
            el('td', {}, el('code', { text: finding.excerpt })),
          ]);
        })),
      ]),
    ]));
  }

  function renderFailed(failed) {
    if (!failed || failed.length === 0) {
      return null;
    }
    return el('details', { class: 'wp2v-failed' }, [
      el('summary', { text: sprintf(_n('%d URL could not be saved', '%d URLs could not be saved', failed.length, 'wp2vercel'), failed.length) }),
      el('table', { class: 'widefat striped' }, el('tbody', {}, failed.map(function (row) {
        return el('tr', {}, [
          el('td', {}, el('code', { text: row.url })),
          el('td', { text: row.note }),
          el('td', { class: 'wp2v-muted', text: row.found_on ? sprintf(__('linked from %s', 'wp2vercel'), row.found_on) : '' }),
        ]);
      }))),
    ]);
  }

  function renderLeftOut(leftOut) {
    if (!leftOut || leftOut.length === 0) {
      return null;
    }
    return el('details', { class: 'wp2v-failed' }, [
      el('summary', { text: sprintf(_n('%d page was left out', '%d pages were left out', leftOut.length, 'wp2vercel'), leftOut.length) }),
      el('p', { class: 'wp2v-muted', text: __('These pages ask for a password or refuse visitors, so they are not part of the published site. A menu or link that points to one of them leads to the not-found page there.', 'wp2vercel') }),
      el('table', { class: 'widefat striped' }, el('tbody', {}, leftOut.map(function (row) {
        return el('tr', {}, [
          el('td', {}, el('code', { text: row.url })),
          el('td', { class: 'wp2v-muted', text: row.found_on ? sprintf(__('linked from %s', 'wp2vercel'), row.found_on) : '' }),
        ]);
      }))),
    ]);
  }

  function renderFormPages(build) {
    if (!build.form_pages_total) {
      return null;
    }
    return el('details', { class: 'wp2v-failed' }, [
      el('summary', { text: sprintf(
        _n('%d page has a form that will not send', '%d pages have a form that will not send', build.form_pages_total, 'wp2vercel'),
        build.form_pages_total
      ) }),
      el('p', { class: 'wp2v-muted', text: __('These forms send their entries to WordPress, and the published site has no WordPress behind it. A visitor who fills one in gets an error and nothing arrives. Send the form to a form service instead, or take it off the page.', 'wp2vercel') }),
      el('table', { class: 'widefat striped' }, el('tbody', {}, build.form_pages.map(function (page) {
        return el('tr', {}, el('td', {}, el('code', { text: page })));
      }))),
    ].concat(extend(extensions.formPagesHelp.map(function (renderer) {
      return function (core) { return renderer(core, build); };
    }))));
  }

  const CLOCK = { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' };

  function localClock(utc) {
    const moment = new Date(String(utc).replace(' ', 'T') + 'Z');
    return Number.isNaN(moment.getTime()) ? String(utc).slice(11) : moment.toLocaleTimeString([], CLOCK);
  }

  function renderLog() {
    if (state.log.length === 0) {
      return null;
    }
    const box = el('pre', { class: 'wp2v-log' }, state.log.map(function (entry) {
      return el('span', { class: 'is-' + entry.level, text: localClock(entry.created_at) + '  ' + entry.message + '\n' });
    }));
    window.requestAnimationFrame(function () {
      box.scrollTop = box.scrollHeight;
    });
    return box;
  }

  const READINESS_MARKS = {
    ok: ['dashicons-yes-alt', __('Ready:', 'wp2vercel')],
    todo: ['dashicons-warning', __('To do:', 'wp2vercel')],
    note: ['dashicons-info-outline', __('Optional:', 'wp2vercel')],
  };

  function hasPublished(snapshot) {
    return (snapshot.history || []).some(function (build) { return build.status === 'completed'; });
  }

  function checkReadiness() {
    state.readiness = { loading: true };
    apiFetch({ path: API + '/readiness' })
      .then(function (result) { state.readiness = { items: result.items || [] }; })
      .catch(function (error) { state.readiness = { error: error.message }; })
      .then(render);
  }

  function readinessAction(item) {
    if (item.state === 'ok') {
      return null;
    }
    if (item.id === 'permalinks') {
      return boot.permalinksUrl ? el('a', { href: boot.permalinksUrl, text: __('Open Permalinks', 'wp2vercel') }) : null;
    }
    return el('a', {
      href: '#',
      onclick: function (event) { event.preventDefault(); api.openTab('settings'); },
      text: __('Open Settings', 'wp2vercel'),
    });
  }

  function renderReadiness(snapshot) {
    if (snapshot.active || hasPublished(snapshot)) {
      return null;
    }
    if (state.readiness === null) {
      checkReadiness();
    }
    const readiness = state.readiness;
    const body = readiness.loading
      ? el('p', { class: 'wp2v-muted', text: __('Checking this site…', 'wp2vercel') })
      : readiness.error
        ? el('p', { class: 'wp2v-muted', text: readiness.error })
        : el('ul', { class: 'wp2v-ready-list' }, readiness.items.map(function (item) {
            const mark = READINESS_MARKS[item.state] || READINESS_MARKS.note;
            const action = readinessAction(item);
            return el('li', { class: 'wp2v-ready-' + item.state }, [
              el('span', { class: 'dashicons ' + mark[0], 'aria-hidden': 'true' }),
              el('span', {}, [
                el('span', { class: 'screen-reader-text', text: mark[1] + ' ' }),
                item.text,
                action ? ' ' : null,
                action,
              ]),
            ]);
          }));
    return el('div', { class: 'wp2v-card wp2v-ready' }, [
      el('h2', { text: __('Before the first publish', 'wp2vercel') }),
      body,
      readiness.loading ? null : el('p', {}, el('button', { class: 'button', onclick: function () { checkReadiness(); render(); }, text: __('Check again', 'wp2vercel') })),
    ]);
  }

  function renderPublish() {
    const snapshot = state.snapshot;
    if (!snapshot) {
      return el('p', { text: __('Loading…', 'wp2vercel') });
    }
    const build = snapshot.build;
    const active = snapshot.active;
    const actions = el('div', { class: 'wp2v-actions' }, active
      ? [el('button', { class: 'button', onclick: cancel, text: __('Cancel', 'wp2vercel') })]
      : [
          el('button', { class: 'button button-primary button-hero', disabled: state.busy, onclick: function () { start({ type: 'full' }); }, text: __('Publish site', 'wp2vercel') }),
          el('button', { class: 'button button-hero', disabled: state.busy, onclick: function () { start({ type: 'full', deploy: false }); }, text: __('Export only', 'wp2vercel') }),
        ].concat(extend(extensions.publishActions), snapshot.download
          ? [el('a', { class: 'button button-hero', href: snapshot.download, text: __('Download as zip', 'wp2vercel'), title: __('The files of the last publish as they are saved on this server', 'wp2vercel') })]
          : []));

    return el('div', {}, [
      renderReadiness(snapshot),
      actions,
      build ? el('div', { class: 'wp2v-card' }, [
        el('h2', { text: sprintf(
          build.type === 'restore' ? __('Restore #%1$d · %2$s', 'wp2vercel') : __('Publish #%1$d · %2$s', 'wp2vercel'),
          build.id,
          build.status
        ) }),
        active ? renderProgress(build, snapshot.counts, snapshot.idle_seconds || 0) : renderResult(build, snapshot.counts),
        active ? null : renderFindings(build),
        renderFailed(snapshot.failed),
        renderLeftOut(snapshot.left_out),
        renderFormPages(build),
        renderLog(),
      ]) : el('p', { class: 'wp2v-muted', text: __('Nothing has been published yet.', 'wp2vercel') }),
    ].concat(extend(extensions.publishPanels)));
  }

  function extend(renderers) {
    return renderers.flatMap(function (renderer) {
      try {
        return [].concat(renderer(api) || []);
      } catch (error) {
        return [el('div', { class: 'notice inline notice-error' }, el('p', { text: error.message }))];
      }
    });
  }

  const CONTROLS = 'input:not([type="hidden"]), select, textarea';
  let fieldIds = {};

  function fieldId(label) {
    const base = 'wp2v-field-' + (String(label).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'unnamed');
    fieldIds[base] = (fieldIds[base] || 0) + 1;
    return fieldIds[base] === 1 ? base : base + '-' + fieldIds[base];
  }

  function onlyControl(control) {
    if (!control || typeof control.matches !== 'function') {
      return null;
    }
    if (control.matches(CONTROLS)) {
      return control;
    }
    const inside = control.querySelectorAll(CONTROLS);
    return inside.length === 1 ? inside[0] : null;
  }

  function field(label, control, help) {
    const target = label ? onlyControl(control) : null;
    if (target && !target.id) {
      target.id = fieldId(label);
    }
    if (target && help) {
      target.setAttribute('aria-describedby', target.id + '-help');
    }
    return el('tr', {}, [
      el('th', { scope: 'row' }, el('label', { for: target ? target.id : null, text: label })),
      el('td', {}, [control, help ? el('p', { class: 'description', id: target ? target.id + '-help' : null }, help) : null]),
    ]);
  }

  function textInput(key, attributes) {
    return el('input', Object.assign({
      type: 'text',
      class: 'regular-text',
      value: state.settings[key] || '',
      oninput: function (event) { state.settings[key] = event.target.value; },
    }, attributes || {}));
  }

  function secretInput(key) {
    if (state.settings['external_' + key]) {
      return el('code', { text: __('Set in wp-config.php or the environment', 'wp2vercel') });
    }
    return el('input', {
      type: 'password',
      class: 'regular-text',
      autocomplete: 'new-password',
      placeholder: state.settings['has_' + key] ? __('Saved. Type to replace.', 'wp2vercel') : '',
      oninput: function (event) { state.settings[key] = event.target.value; },
    });
  }

  function checkbox(key, label) {
    return el('label', {}, [
      el('input', { type: 'checkbox', checked: !!state.settings[key], onchange: function (event) { state.settings[key] = event.target.checked; } }),
      ' ' + label,
    ]);
  }

  function textarea(key, placeholder) {
    return el('textarea', {
      class: 'large-text code',
      rows: '4',
      placeholder: placeholder,
      oninput: function (event) { state.settings[key] = event.target.value; },
    }, state.settings[key] || '');
  }

  const REJECTED_HINTS = {
    production_url: __('Public site address needs a full address such as https://www.example.com.', 'wp2vercel'),
    fetch_url: __('Fetch address has to start with http:// or https://.', 'wp2vercel'),
    github_repository: __('Repository has to be owner/name.', 'wp2vercel'),
    github_branch: __('Publish branch can only contain letters, digits and the characters . _ - /.', 'wp2vercel'),
    github_base_branch: __('Base branch can only contain letters, digits and the characters . _ - /.', 'wp2vercel'),
  };

  function runningPublish() {
    const snapshot = state.snapshot;
    return snapshot && snapshot.active && snapshot.build && snapshot.build.status !== 'waiting' ? snapshot.build.id : null;
  }

  function settingsHeldNote() {
    const running = runningPublish();
    return running === null ? null : el('div', { class: 'notice notice-info inline' }, el('p', {
      text: sprintf(__('Publish #%d is running. Settings can be saved again when it has ended, so that one publish does not mix old and new settings.', 'wp2vercel'), running),
    }));
  }

  function saveSettings(then) {
    const submitted = state.settings;
    state.busy = true;
    apiFetch({ path: API + '/settings', method: 'POST', data: submitted })
      .then(function (settings) {
        const rejected = settings.rejected || [];
        delete settings.rejected;
        rejected.forEach(function (key) { settings[key] = submitted[key]; });
        state.settings = settings;
        state.busy = false;
        state.readiness = null;
        if (rejected.length) {
          setNotice('warning', [__('Not saved, because the value is not valid:', 'wp2vercel')]
            .concat(rejected.map(function (key) { return REJECTED_HINTS[key] || key; }))
            .concat(__('Everything else was saved.', 'wp2vercel'))
            .join(' '));
        } else if (then) {
          then();
        } else {
          setNotice('success', __('Settings saved.', 'wp2vercel'));
        }
      })
      .catch(function (error) {
        state.busy = false;
        setNotice('error', error.message);
      });
  }

  function testConnection() {
    saveSettings(function () {
      apiFetch({ path: API + '/test-connection', method: 'POST', data: { target: state.settings.deploy_target } })
        .then(function (result) { setNotice('success', result.message); })
        .catch(function (error) { setNotice('error', error.message); });
    });
  }

  function renderTarget() {
    const target = state.settings.deploy_target;
    const options = [
      ['none', __('This server only', 'wp2vercel')],
      ['github', __('GitHub (Vercel or Netlify deploys from the repository)', 'wp2vercel')],
      ['vercel', __('Vercel (direct upload)', 'wp2vercel')],
      ['netlify', __('Netlify (direct upload)', 'wp2vercel')],
    ];
    const rows = [field(__('Publish to', 'wp2vercel'), el('fieldset', {}, options.map(function (option) {
      return el('p', {}, el('label', {}, [
        el('input', {
          type: 'radio',
          name: 'wp2v-target',
          checked: target === option[0],
          onchange: function () { state.settings.deploy_target = option[0]; render(); },
        }),
        ' ' + option[1],
      ]));
    })))];

    if (target === 'github') {
      rows.push(field(__('Repository', 'wp2vercel'), textInput('github_repository', { placeholder: 'owner/name' }), __('The repository has to exist with at least one commit. Import it as a project on Vercel or as a site on Netlify, and it deploys every publish.', 'wp2vercel')));
      rows.push(field(__('Publish branch', 'wp2vercel'), textInput('github_branch'), __('Each publish is one commit on this branch. Vercel or Netlify builds a preview from it; merge into your production branch to go live.', 'wp2vercel')));
      rows.push(field(__('Base branch', 'wp2vercel'), textInput('github_base_branch'), __('Used to create the publish branch the first time.', 'wp2vercel')));
      rows.push(field(__('Directory', 'wp2vercel'), textInput('github_directory', { placeholder: __('repository root', 'wp2vercel') })));
      rows.push(field(__('Access token', 'wp2vercel'), secretInput('github_token'), [
        __('A fine-grained token with Contents: read and write on this one repository. Create it on GitHub under Settings → Developer settings → Personal access tokens: ', 'wp2vercel'),
        link('https://github.com/settings/personal-access-tokens/new', 'github.com/settings/personal-access-tokens/new'),
      ]));
    }
    if (target === 'vercel') {
      rows.push(field(__('Project name', 'wp2vercel'), textInput('vercel_project')));
      rows.push(field(__('Team ID', 'wp2vercel'), textInput('vercel_team_id', { placeholder: 'team_…' }), __('Leave empty for a personal account. For a team it is on Vercel under Team Settings → General → Team ID.', 'wp2vercel')));
      rows.push(field(__('Access token', 'wp2vercel'), secretInput('vercel_token'), [
        __('Create it on Vercel under Account Settings → Tokens, with the scope of the account or team that owns the project: ', 'wp2vercel'),
        link('https://vercel.com/account/settings/tokens', 'vercel.com/account/settings/tokens'),
      ]));
      rows.push(field(__('Release', 'wp2vercel'), checkbox('vercel_production', __('Publish straight to production instead of a preview URL', 'wp2vercel'))));
    }
    if (target === 'netlify') {
      rows.push(field(__('Site', 'wp2vercel'), textInput('netlify_site', { placeholder: 'name.netlify.app' }), __('The site has to exist on Netlify. Enter its name as in name.netlify.app, its address, or the Site ID under Site configuration → General.', 'wp2vercel')));
      rows.push(field(__('Access token', 'wp2vercel'), secretInput('netlify_token'), [
        __('A personal access token of a member of the team that owns the site. Create it on Netlify under User settings → Applications → Personal access tokens: ', 'wp2vercel'),
        link('https://app.netlify.com/user/applications#personal-access-tokens', 'app.netlify.com/user/applications'),
      ]));
      rows.push(field(__('Release', 'wp2vercel'), checkbox('netlify_production', __('Publish straight to the live site instead of a draft deploy with its own address', 'wp2vercel'))));
      rows.push(field(__('Redirects', 'wp2vercel'), el('p', { class: 'description', text: __('Redirects and headers are written to _redirects and _headers, the files Netlify reads.', 'wp2vercel') })));
    }
    return rows;
  }

  function renderSettings() {
    if (!state.settings) {
      return el('p', { text: __('Loading…', 'wp2vercel') });
    }
    return el('form', { onsubmit: function (event) { event.preventDefault(); saveSettings(); } }, [
      el('h2', { text: __('Addresses', 'wp2vercel') }),
      el('table', { class: 'form-table', role: 'presentation' }, el('tbody', {}, [
        field(__('Private WordPress address', 'wp2vercel'), el('code', { text: state.settings.origin_url }), __('Every mention of this address is removed from the published files.', 'wp2vercel')),
        field(__('Public site address', 'wp2vercel'), textInput('production_url', { placeholder: 'https://www.example.com' }), __('Used for canonical tags, social tags and sitemaps.', 'wp2vercel')),
      ])),
      el('h2', { text: __('Publish target', 'wp2vercel') }),
      el('table', { class: 'form-table', role: 'presentation' }, el('tbody', {}, renderTarget())),
      el('h2', { text: __('Content', 'wp2vercel') }),
      el('table', { class: 'form-table', role: 'presentation' }, el('tbody', {}, [
        field(__('Extra URLs', 'wp2vercel'), textarea('additional_urls', '/landing/\n/downloads/brochure.pdf'), __('One per line. For pages nothing links to.', 'wp2vercel')),
        field(__('Exclude', 'wp2vercel'), textarea('exclude_patterns', '/members/\n~^/tag/~'), __('One per line. Plain text matches anywhere in the path; wrap a regular expression in ~.', 'wp2vercel')),
        field(__('Extra redirects', 'wp2vercel'), textarea('extra_redirects', '/old-page/ /new-page/ 301'), __('One per line: from, to, status. Redirects from Redirection, Yoast SEO Premium, Rank Math, Safe Redirect Manager and Simple 301 Redirects are included automatically.', 'wp2vercel')),
        field(__('Include', 'wp2vercel'), el('fieldset', {}, [
          el('p', {}, checkbox('include_feeds', __('RSS feeds', 'wp2vercel'))),
          el('p', {}, checkbox('include_author_archives', __('Author archives (these expose login names)', 'wp2vercel'))),
        ])),
      ])),
      el('h2', { text: __('How WordPress fetches itself', 'wp2vercel') }),
      el('table', { class: 'form-table', role: 'presentation' }, el('tbody', {}, [
        field(
          __('Fetch address', 'wp2vercel'),
          textInput('fetch_url', { placeholder: state.settings.origin_url, disabled: (state.settings.locked || []).indexOf('fetch_url') !== -1 }),
          (state.settings.locked || []).indexOf('fetch_url') !== -1
            ? __('On a multisite network only a network administrator can change this, because it decides which server the export reads from.', 'wp2vercel')
            : __('Only needed when the server cannot reach its own public address, for example inside Docker or behind a zero-trust gateway.', 'wp2vercel')
        ),
        field(__('Parallel requests', 'wp2vercel'), textInput('fetch_concurrency', { type: 'number', min: '1', max: '16', class: 'small-text' })),
        field(__('Timeout (seconds)', 'wp2vercel'), textInput('fetch_timeout', { type: 'number', min: '5', max: '300', class: 'small-text' })),
        field(__('TLS', 'wp2vercel'), checkbox('fetch_ssl_verify', __('Verify the certificate', 'wp2vercel'))),
        field(__('Basic auth user', 'wp2vercel'), textInput('fetch_basic_auth_user')),
        field(__('Basic auth password', 'wp2vercel'), secretInput('fetch_basic_auth_password')),
      ])),
      settingsHeldNote(),
      el('p', { class: 'submit' }, [
        el('button', { type: 'submit', class: 'button button-primary', disabled: state.busy || runningPublish() !== null, text: __('Save settings', 'wp2vercel') }),
        ' ',
        state.settings.deploy_target !== 'none'
          ? el('button', { type: 'button', class: 'button', disabled: state.busy || runningPublish() !== null, onclick: testConnection, text: __('Save and test connection', 'wp2vercel') })
          : null,
      ]),
    ]);
  }

  function renderTab() {
    if (state.tab === 'publish') {
      return renderPublish();
    }
    if (state.tab === 'settings') {
      return renderSettings();
    }
    const custom = extensions.tabs.find(function (tab) { return tab.id === state.tab; });
    return custom ? el('div', {}, extend([custom.render])) : renderPublish();
  }

  const TYPED = /^(text|url|search|tel|password|textarea)$/;

  function described(node) {
    return node.tagName + ':' + (node.type || '') + ':' + (node.tagName === 'A' || node.tagName === 'BUTTON' ? node.textContent : '');
  }

  function focusMark() {
    const active = document.activeElement;
    if (!active || active === root || !root.contains(active)) {
      return null;
    }
    const path = [];
    for (let node = active; node !== root; node = node.parentNode) {
      path.unshift(Array.prototype.indexOf.call(node.parentNode.childNodes, node));
    }
    return {
      id: active.id,
      path: path,
      described: described(active),
      range: TYPED.test(active.type || '') ? [active.selectionStart, active.selectionEnd] : null,
    };
  }

  function restoreFocus(mark) {
    if (!mark) {
      return;
    }
    const next = mark.id
      ? document.getElementById(mark.id)
      : mark.path.reduce(function (node, index) { return node ? node.childNodes[index] : null; }, root);
    if (!next || !root.contains(next) || next.disabled || typeof next.focus !== 'function' || (!mark.id && described(next) !== mark.described)) {
      return;
    }
    next.focus({ preventScroll: true });
    if (mark.range && TYPED.test(next.type || '')) {
      next.setSelectionRange(mark.range[0], mark.range[1]);
    }
  }

  function render() {
    const tabs = [['publish', __('Publish', 'wp2vercel')], ['settings', __('Settings', 'wp2vercel')]]
      .concat(extensions.tabs.map(function (tab) { return [tab.id, tab.label]; }));
    const focused = focusMark();
    fieldIds = {};
    root.replaceChildren(
      el('nav', { class: 'nav-tab-wrapper' }, tabs.map(function (tab) {
        return el('a', {
          href: '#',
          class: 'nav-tab' + (state.tab === tab[0] ? ' nav-tab-active' : ''),
          'aria-current': state.tab === tab[0] ? 'page' : null,
          onclick: function (event) { event.preventDefault(); state.tab = tab[0]; state.notice = null; render(); },
          text: tab[1],
        });
      })),
      state.notice ? el('div', { class: 'notice inline notice-' + state.notice.type, role: state.notice.type === 'error' ? 'alert' : 'status' }, el('p', { text: state.notice.message })) : '',
      state.missedAnswers >= MISSED_ANSWERS_SHOWN ? el('div', { class: 'notice inline notice-warning', role: 'status' }, el('p', {
        text: __('WordPress has not answered the last requests of this screen, so what you see may be out of date. The screen keeps trying. Check your connection, and reload the page when you were signed out in the meantime.', 'wp2vercel'),
      })) : '',
      state.loadError ? el('div', { class: 'notice inline notice-error', role: 'alert' }, el('p', { text: state.loadError })) : renderTab()
    );
    restoreFocus(focused);
  }

  const api = {
    el: el,
    link: link,
    field: field,
    apiFetch: apiFetch,
    start: start,
    refresh: refresh,
    render: render,
    setNotice: setNotice,
    absorb: function (snapshot) { absorb(snapshot); render(); schedule(); },
    snapshot: function () { return state.snapshot; },
    isBusy: function () { return state.busy || !!(state.snapshot && state.snapshot.active); },
    runningPublish: runningPublish,
    settingsHeldNote: settingsHeldNote,
    openTab: function (id) { state.tab = id; state.notice = null; render(); },
    registerTab: function (id, label, renderer) { extensions.tabs.push({ id: id, label: label, render: renderer }); render(); },
    registerPublishAction: function (renderer) { extensions.publishActions.push(renderer); render(); },
    registerPublishPanel: function (renderer) { extensions.publishPanels.push(renderer); render(); },
    registerFindingLabels: function (labels) { Object.assign(extensions.findingLabels, labels); render(); },
    registerFindingsHelp: function (renderer) { extensions.findingsHelp.push(renderer); render(); },
    registerFormPagesHelp: function (renderer) { extensions.formPagesHelp.push(renderer); render(); },
    registerStepLabels: function (labels) { Object.assign(extensions.stepLabels, labels); render(); },
  };
  window.wp2vercel = api;

  function loadFailed(error) {
    state.loadError = sprintf(
      __('WP2Vercel could not load its data: %s This screen talks to WordPress through the REST API, under /wp-json/wp2vercel/v1/. When a security plugin or the firewall of your host blocks the REST API, allow it for signed-in administrators, then reload this page.', 'wp2vercel'),
      error && error.message ? error.message : __('WordPress did not answer.', 'wp2vercel')
    );
    render();
  }

  render();
  apiFetch({ path: API + '/settings' }).then(function (settings) {
    state.settings = settings;
    render();
  }).catch(loadFailed);
  refresh().then(function () {
    if (boot.publishPost && !state.snapshot.active) {
      window.history.replaceState({}, '', window.location.pathname + '?page=wp2vercel');
      start({ type: 'single', post_ids: [boot.publishPost] });
    }
  }).catch(loadFailed);
})();
