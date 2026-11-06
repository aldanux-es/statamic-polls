/**
 * Statamic Polls — front-end enhancement.
 *
 * The poll markup is static-cache safe: it never says whether *this* visitor voted. This
 * script asks the state endpoint and toggles the [data-poll-*] blocks, and sends votes with
 * fetch. To keep page views free of PHP on static-cached sites, the endpoint is only asked
 * when the answer can differ from the markup: this browser voted before (remembered in
 * localStorage), the poll is for logged-in users, or its results are shown before closing.
 *
 * Usage: import it in your bundle (it initialises itself), or load the published copy:
 *   <script type="module" src="/vendor/statamic-polls/js/polls.js"></script>
 * Call initPolls(element) again for polls injected after page load.
 */

const SELECTOR = '[data-poll]';
const VOTED_KEY = 'statamic-polls:voted:';

export function initPolls(root = document) {
    root.querySelectorAll(SELECTOR).forEach(setup);
}

function setup(poll) {
    if (poll.dataset.pollReady) {
        return;
    }

    poll.dataset.pollReady = 'true';

    // Scheduled and closed polls are rendered in their final form by the server.
    if (poll.dataset.pollStatus !== 'open') {
        return;
    }

    const form = poll.querySelector('[data-poll-form]');

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        submit(poll, form);
    });

    if (needsState(poll)) {
        fetchState(poll).then((state) => state && apply(poll, state));
    }
}

function needsState(poll) {
    return poll.dataset.pollAudience === 'authenticated'
        || poll.dataset.pollResults !== 'after_close'
        || remembersVote(poll.dataset.poll);
}

function remembersVote(id) {
    try {
        return window.localStorage.getItem(VOTED_KEY + id) === '1';
    } catch {
        // Storage blocked: ask the server, which knows through the voter cookie.
        return true;
    }
}

function rememberVote(id) {
    try {
        window.localStorage.setItem(VOTED_KEY + id, '1');
    } catch {
        // Ignore: the server still enforces one vote per voter.
    }
}

async function fetchState(poll) {
    try {
        const response = await fetch(poll.dataset.pollStateUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        return response.ok ? await response.json() : null;
    } catch {
        return null;
    }
}

async function submit(poll, form) {
    const button = form.querySelector('[data-poll-submit]');
    const error = poll.querySelector('[data-poll-error]');

    button?.setAttribute('disabled', '');
    error?.setAttribute('hidden', '');

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));

        if (response.ok || response.status === 409) {
            apply(poll, data);

            return;
        }

        showError(error, data.message);
    } catch {
        showError(error);
    } finally {
        button?.removeAttribute('disabled');
    }
}

function apply(poll, state) {
    const form = poll.querySelector('[data-poll-form]');
    const thanks = poll.querySelector('[data-poll-thanks]');
    const login = poll.querySelector('[data-poll-login]');
    const results = poll.querySelector('[data-poll-live-results]');

    poll.dataset.pollStatus = state.status;
    poll.toggleAttribute('data-poll-voted', Boolean(state.voted));

    if (state.voted) {
        rememberVote(poll.dataset.poll);
    }

    toggle(form, state.can_vote);
    toggle(thanks, state.voted);
    toggle(login, state.login_required && !state.voted);

    if (results && state.results) {
        renderResults(results, state.results);
        toggle(results, true);
    }
}

function renderResults(list, results) {
    list.replaceChildren(
        ...results.options.map((option) => {
            const item = document.createElement('li');
            const label = document.createElement('span');
            const bar = document.createElement('progress');
            const count = document.createElement('span');

            item.className = option.is_winner ? 'poll__result poll__result--winner' : 'poll__result';
            label.className = 'poll__label';
            label.textContent = option.label;
            bar.className = 'poll__bar';
            bar.max = 100;
            bar.value = option.percent;
            count.className = 'poll__count';
            count.textContent = `${option.percent}% · ${option.votes}`;

            item.append(label, bar, count);

            return item;
        }),
    );
}

function showError(element, message) {
    if (!element) {
        return;
    }

    element.textContent = message || element.dataset.pollErrorDefault || 'Error';
    element.removeAttribute('hidden');
}

function toggle(element, visible) {
    element?.toggleAttribute('hidden', !visible);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initPolls());
    } else {
        initPolls();
    }
}
