(function () {
  'use strict';

  const apiFetch = window.wp.apiFetch;
  const __ = window.wp.i18n.__;
  const sprintf = window.wp.i18n.sprintf;
  const boot = window.wp2vercelBoot || {};
  const root = document.getElementById('wp2vercel-app');
  const API = '/wp2vercel/v1';
  const POLL_MS = 2500;

  const state = {
    tab: 'publish',
    snapshot: null,
    settings: null,
    log: [],
    lastLogId: 0,
    logBuildId: 0,
    busy: false,
    notice: null,
    timer: null,
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
  const extensions = { tabs: [], publishActions: [], publishPanels: [], findingLabels: {} };

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
    state.snapshot = snapshot;
  }

  function schedule() {
    window.clearTimeout(state.timer);
    if (state.snapshot && state.snapshot.active) {
      state.timer = window.setTimeout(advance, POLL_MS);
    }
  }

  function advance() {
    apiFetch({ path: API + '/tick?after_log=' + state.lastLogId, method: 'POST' })
      .then(function (snapshot) {
        absorb(snapshot);
        render();
        schedule();
      })
      .catch(function () {
        state.timer = window.setTimeout(advance, POLL_MS * 2);
      });
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
    apiFetch({ path: API + '/builds/cancel', method: 'POST' }).then(function (snapshot) {
      absorb(snapshot);
      render();
    });
  }

  function renderProgress(build, counts) {
    const steps = build.steps || STEPS;
    const index = steps.indexOf(build.step);
    const done = counts ? counts.fetched + counts.redirect + counts.skipped + counts.failed : 0;
    const total = counts ? done + counts.queued : 0;
    return el('div', { class: 'wp2v-progress' }, [
      el('ol', { class: 'wp2v-steps' }, steps.map(function (step, position) {
        const status = position < index ? 'is-done' : position === index ? 'is-current' : '';
        return el('li', { class: status, text: STEP_LABELS[step] || step });
      })),
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
    if (counts) {
      rows.push([__('URLs', 'wp2vercel'), sprintf(__('%1$d saved, %2$d redirects, %3$d failed', 'wp2vercel'), counts.fetched, counts.redirect, counts.failed)]);
    }
    const list = el('dl', { class: 'wp2v-facts' }, rows.flatMap(function (row) {
      return [el('dt', { text: row[0] }), el('dd', { text: row[1] })];
    }));
    if (deployment.url) {
      list.appendChild(el('dt', { text: build.target === 'vercel' ? __('Deployment', 'wp2vercel') : __('Branch', 'wp2vercel') }));
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
      el('h3', { text: sprintf(__('Leak scan: %d findings', 'wp2vercel'), build.findings_total) }),
      el('p', { class: 'wp2v-muted', text: build.blocked
        ? __('Publishing was stopped because of these findings. Nothing was deployed.', 'wp2vercel')
        : __('These published files still mention the private WordPress address, or are file types that should not be public.', 'wp2vercel') }),
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
    ]);
  }

  function renderFailed(failed) {
    if (!failed || failed.length === 0) {
      return null;
    }
    return el('details', { class: 'wp2v-failed' }, [
      el('summary', { text: sprintf(__('%d URLs could not be saved', 'wp2vercel'), failed.length) }),
      el('table', { class: 'widefat striped' }, el('tbody', {}, failed.map(function (row) {
        return el('tr', {}, [
          el('td', {}, el('code', { text: row.url })),
          el('td', { text: row.note }),
          el('td', { class: 'wp2v-muted', text: row.found_on ? sprintf(__('linked from %s', 'wp2vercel'), row.found_on) : '' }),
        ]);
      }))),
    ]);
  }

  function renderLog() {
    if (state.log.length === 0) {
      return null;
    }
    const box = el('pre', { class: 'wp2v-log' }, state.log.map(function (entry) {
      return el('span', { class: 'is-' + entry.level, text: entry.created_at.slice(11) + '  ' + entry.message + '\n' });
    }));
    window.requestAnimationFrame(function () {
      box.scrollTop = box.scrollHeight;
    });
    return box;
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
        ].concat(extend(extensions.publishActions)));

    return el('div', {}, [
      actions,
      build ? el('div', { class: 'wp2v-card' }, [
        el('h2', { text: sprintf(
          build.type === 'restore' ? __('Restore #%1$d · %2$s', 'wp2vercel') : __('Publish #%1$d · %2$s', 'wp2vercel'),
          build.id,
          build.status
        ) }),
        active ? renderProgress(build, snapshot.counts) : renderResult(build, snapshot.counts),
        active ? null : renderFindings(build),
        renderFailed(snapshot.failed),
        renderLog(),
      ]) : el('p', { class: 'wp2v-muted', text: __('Nothing has been published yet. Check the settings, then publish.', 'wp2vercel') }),
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

  function field(label, control, help) {
    return el('tr', {}, [
      el('th', { scope: 'row' }, el('label', { text: label })),
      el('td', {}, [control, help ? el('p', { class: 'description', text: help }) : null]),
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

  function saveSettings(then) {
    state.busy = true;
    apiFetch({ path: API + '/settings', method: 'POST', data: state.settings })
      .then(function (settings) {
        state.settings = settings;
        state.busy = false;
        if (then) {
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
      ['github', __('GitHub (Vercel deploys from the repository)', 'wp2vercel')],
      ['vercel', __('Vercel (direct upload)', 'wp2vercel')],
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
      rows.push(field(__('Repository', 'wp2vercel'), textInput('github_repository', { placeholder: 'owner/name' })));
      rows.push(field(__('Publish branch', 'wp2vercel'), textInput('github_branch'), __('Each publish is one commit on this branch. Vercel builds a preview from it; merge into your production branch to go live.', 'wp2vercel')));
      rows.push(field(__('Base branch', 'wp2vercel'), textInput('github_base_branch'), __('Used to create the publish branch the first time.', 'wp2vercel')));
      rows.push(field(__('Directory', 'wp2vercel'), textInput('github_directory', { placeholder: __('repository root', 'wp2vercel') })));
      rows.push(field(__('Access token', 'wp2vercel'), secretInput('github_token'), __('A fine-grained token with Contents: read and write on this one repository.', 'wp2vercel')));
    }
    if (target === 'vercel') {
      rows.push(field(__('Project name', 'wp2vercel'), textInput('vercel_project')));
      rows.push(field(__('Team ID', 'wp2vercel'), textInput('vercel_team_id', { placeholder: 'team_…' }), __('Leave empty for a personal account.', 'wp2vercel')));
      rows.push(field(__('Access token', 'wp2vercel'), secretInput('vercel_token')));
      rows.push(field(__('Release', 'wp2vercel'), checkbox('vercel_production', __('Publish straight to production instead of a preview URL', 'wp2vercel'))));
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
        field(__('Fetch address', 'wp2vercel'), textInput('fetch_url', { placeholder: state.settings.origin_url }), __('Only needed when the server cannot reach its own public address, for example inside Docker or behind a zero-trust gateway.', 'wp2vercel')),
        field(__('Parallel requests', 'wp2vercel'), textInput('fetch_concurrency', { type: 'number', min: '1', max: '16', class: 'small-text' })),
        field(__('Timeout (seconds)', 'wp2vercel'), textInput('fetch_timeout', { type: 'number', min: '5', max: '300', class: 'small-text' })),
        field(__('TLS', 'wp2vercel'), checkbox('fetch_ssl_verify', __('Verify the certificate', 'wp2vercel'))),
        field(__('Basic auth user', 'wp2vercel'), textInput('fetch_basic_auth_user')),
        field(__('Basic auth password', 'wp2vercel'), secretInput('fetch_basic_auth_password')),
      ])),
      el('p', { class: 'submit' }, [
        el('button', { type: 'submit', class: 'button button-primary', disabled: state.busy, text: __('Save settings', 'wp2vercel') }),
        ' ',
        state.settings.deploy_target !== 'none'
          ? el('button', { type: 'button', class: 'button', disabled: state.busy, onclick: testConnection, text: __('Save and test connection', 'wp2vercel') })
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

  function render() {
    const tabs = [['publish', __('Publish', 'wp2vercel')], ['settings', __('Settings', 'wp2vercel')]]
      .concat(extensions.tabs.map(function (tab) { return [tab.id, tab.label]; }));
    root.replaceChildren(
      el('nav', { class: 'nav-tab-wrapper' }, tabs.map(function (tab) {
        return el('a', {
          href: '#',
          class: 'nav-tab' + (state.tab === tab[0] ? ' nav-tab-active' : ''),
          onclick: function (event) { event.preventDefault(); state.tab = tab[0]; state.notice = null; render(); },
          text: tab[1],
        });
      })),
      state.notice ? el('div', { class: 'notice inline notice-' + state.notice.type }, el('p', { text: state.notice.message })) : '',
      renderTab()
    );
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
    openTab: function (id) { state.tab = id; state.notice = null; render(); },
    registerTab: function (id, label, renderer) { extensions.tabs.push({ id: id, label: label, render: renderer }); render(); },
    registerPublishAction: function (renderer) { extensions.publishActions.push(renderer); render(); },
    registerPublishPanel: function (renderer) { extensions.publishPanels.push(renderer); render(); },
    registerFindingLabels: function (labels) { Object.assign(extensions.findingLabels, labels); render(); },
  };
  window.wp2vercel = api;

  render();
  apiFetch({ path: API + '/settings' }).then(function (settings) {
    state.settings = settings;
    render();
  });
  refresh().then(function () {
    if (boot.publishPost && !state.snapshot.active) {
      window.history.replaceState({}, '', window.location.pathname + '?page=wp2vercel');
      start({ type: 'single', post_ids: [boot.publishPost] });
    }
  });
})();
