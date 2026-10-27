/* =========================================
   KWETU CREATIONS — COOKIE CONSENT
   =========================================

   What this file does:

   1. Shows a consent bar once, remembers the answer, and never shows it
      again until the visitor reopens Cookie Settings or the recorded
      version below changes.

   2. Stores that answer in localStorage and turns it into the only real
      switch the site has: whether the analytics tag below is allowed to
      load at all.

   3. Loads nothing third-party for tracking unless BOTH conditions hold:
      the visitor granted Analytics, and a Measurement ID has been
      configured below. With an empty ID there is no request, no gtag
      stub and no dataLayer.

   To connect Google Analytics later:
     - create the web data stream in your Google Analytics account,
     - paste its Measurement ID into CONFIG.analytics.measurementId,
     - publish the site. No other code change is needed; the tag then
       loads only for visitors who accepted Analytics.
======================================== */

(function () {
    "use strict";

    const CONFIG = {
        // Bumping this version asks returning visitors to choose again,
        // which is what you want whenever the categories below change.
        version: 1,
        storageKey: "kwetu-consent-v1",

        analytics: {
            measurementId: ""
        }
    };

    const CATEGORIES = [
        {
            key: "necessary",
            name: "Strictly Necessary",
            locked: true,
            description: "Remembers your choice on this device so the banner does not follow you from page to page. The website cannot honour your preference without it.",
            status: "Always active"
        },
        {
            key: "analytics",
            name: "Analytics",
            description: "Would count which pages were viewed so the studio can see which work attracts interest. There is no analytics service on this website, so nothing in this category loads; your answer is recorded and honoured either way.",
            status: "Not used"
        },
        {
            key: "marketing",
            name: "Marketing",
            description: "Would cover advertising cookies and social-platform trackers that follow you between websites. This website uses neither, so nothing in this category loads and your answer changes nothing on the page.",
            status: "Not used"
        }
    ];

    /* MEMORY — localStorage can be refused by private browsing modes, so
       the decision still works for the current page visit. */
    const memory = { value: null };

    function readStored() {
        try {
            const raw = window.localStorage.getItem(CONFIG.storageKey);

            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return memory.value;
        }
    }

    function writeStored(state) {
        memory.value = state;

        try {
            window.localStorage.setItem(CONFIG.storageKey, JSON.stringify(state));
        } catch (error) {
            // The in-memory copy above is all that survives this visit.
        }
    }

    function clearStored() {
        memory.value = null;

        try {
            window.localStorage.removeItem(CONFIG.storageKey);
        } catch (error) {
            // Nothing else to clear.
        }
    }

    function asState(stored) {
        if (!stored || stored.version !== CONFIG.version || !stored.categories) return null;

        const saved = stored.categories;
        const categories = {};

        CATEGORIES.forEach(function (category) {
            categories[category.key] = category.locked
                ? true
                : saved[category.key] === true;
        });

        return {
            version: CONFIG.version,
            updatedAt: stored.updatedAt || "",
            categories: categories
        };
    }

    let current = asState(readStored());

    /* TRACKING — the only non-essential service this site is prepared for. */
    function startAnalytics() {
        const id = String(CONFIG.analytics.measurementId || "").trim();

        if (!/^G-[A-Z0-9]{6,}$/.test(id)) return;
        if (document.getElementById("kwetu-ga4")) return;

        window.dataLayer = window.dataLayer || [];

        if (typeof window.gtag !== "function") {
            window.gtag = function () {
                window.dataLayer.push(arguments);
            };
        }

        window.gtag("js", new Date());

        // Marketing stays denied here because no advertising product is
        // connected; the config point is the same Measurement ID stream.
        window.gtag("consent", "update", {
            analytics_storage: "granted",
            ad_storage: "denied",
            ad_user_data: "denied",
            ad_personalization: "denied"
        });

        const tag = document.createElement("script");

        tag.id = "kwetu-ga4";
        tag.async = true;
        tag.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(id);
        document.head.appendChild(tag);

        window.gtag("config", id);
    }

    function applyTracking() {
        if (current && current.categories.analytics) startAnalytics();
    }

    function publish() {
        applyTracking();

        document.dispatchEvent(new CustomEvent("kwetu:consent", {
            detail: {
                categories: current ? current.categories : null,
                savedAt: current ? current.updatedAt : null
            }
        }));
    }

    function save(categories) {
        current = {
            version: CONFIG.version,
            updatedAt: new Date().toISOString(),
            categories: Object.assign({}, categories, { necessary: true })
        };

        writeStored(current);
        publish();

        return current;
    }

    function chosen() {
        return {
            necessary: true,
            analytics: current ? current.categories.analytics : false,
            marketing: current ? current.categories.marketing : false
        };
    }

    /* MARKUP — built here so every page keeps one shared banner instead of
       a copy that can drift out of date. */
    function categoryRow(category, index) {
        const checked = category.locked || (current ? current.categories[category.key] : false);

        return `
            <div class="kw-cs-row">
                <div class="kw-cs-row__copy">
                    <h3 class="kw-cs-row__name">
                        ${category.name}
                        <span class="kw-cs-row__status">${category.status}</span>
                    </h3>
                    <p class="kw-cs-row__text">${category.description}</p>
                </div>

                <label class="kw-switch" for="kw-cs-${category.key}">
                    <input
                        type="checkbox"
                        id="kw-cs-${category.key}"
                        class="kw-switch__input"
                        data-category="${category.key}"
                        ${checked ? "checked" : ""}
                        ${category.locked ? "disabled aria-disabled=\"true\"" : ""}>
                    <span class="kw-switch__track" aria-hidden="true"></span>
                    <span class="kw-switch__hint">${category.locked
                        ? "Locked on"
                        : (checked ? "On" : "Off")}</span>
                </label>
            </div>`;
    }

    function markup() {
        const links = `
            <a href="cookie-policy.html">Cookie Policy</a>
            <a href="privacy-policy.html">Privacy Policy</a>`;

        return `
        <div class="kw-cookie" id="kwetu-cookie" role="region" aria-label="Cookie consent">
            <div class="kw-cookie__bar" id="kwetu-cookie-bar" hidden>
                <div class="kw-cookie__inner">
                    <span class="kw-cookie__mark" aria-hidden="true"></span>

                    <div class="kw-cookie__copy">
                        <p class="kw-cookie__eyebrow">Your privacy, your choice</p>
                        <h2 class="kw-cookie__title">Essential cookies only — unless you say otherwise.</h2>
                        <p class="kw-cookie__text">
                            This website keeps strictly necessary cookies working all the time.
                            Optional analytics cookies are loaded only if you accept them.
                            Read the ${links}.
                        </p>
                    </div>

                    <div class="kw-cookie__actions">
                        <button type="button" class="kw-btn kw-btn--primary" data-consent="accept">
                            Accept All
                        </button>
                        <button type="button" class="kw-btn kw-btn--ghost" data-consent="reject">
                            Reject Non-Essential
                        </button>
                        <button type="button" class="kw-btn kw-btn--plain" data-consent="settings"
                                aria-haspopup="dialog">
                            Cookie Settings
                        </button>
                    </div>
                </div>
            </div>

            <div class="kw-cs" id="kwetu-cookie-settings" hidden>
                <div class="kw-cs__backdrop" data-consent="close"></div>

                <div class="kw-cs__dialog" role="dialog" aria-modal="true"
                     aria-labelledby="kw-cs-title" tabindex="-1">
                    <header class="kw-cs__head">
                        <p class="kw-cookie__eyebrow">Cookie settings</p>
                        <h2 class="kw-cs__title" id="kw-cs-title">Choose what may be stored.</h2>
                        <p class="kw-cs__lede">
                            Necessary cookies are always on because they carry your choice.
                            Everything else waits for your permission and can be changed again
                            from Cookie Settings in the footer.
                        </p>
                    </header>

                    <div class="kw-cs__rows">
                        ${CATEGORIES.map(categoryRow).join("")}
                    </div>

                    <p class="kw-cs__saved" id="kw-cs-saved" role="status" aria-live="polite" hidden></p>

                    <footer class="kw-cs__foot">
                        <div class="kw-cs__foot-links">${links}</div>

                        <div class="kw-cookie__actions">
                            <button type="button" class="kw-btn kw-btn--primary" data-consent="accept">
                                Accept All
                            </button>
                            <button type="button" class="kw-btn kw-btn--gold" data-consent="save">
                                Save Preferences
                            </button>
                            <button type="button" class="kw-btn kw-btn--ghost" data-consent="reject">
                                Reject Non-Essential
                            </button>
                            <button type="button" class="kw-btn kw-btn--plain" data-consent="close">
                                Close
                            </button>
                        </div>
                    </footer>
                </div>
            </div>
        </div>`;
    }

    let root = null;
    let opener = null;

    function mount() {
        const host = document.createElement("div");

        host.innerHTML = markup();
        root = host.firstElementChild;
        document.body.appendChild(root);
        root.addEventListener("change", reflectSwitches);

        if (!current) showBar();
    }

    function bar() {
        return root.querySelector(".kw-cookie__bar");
    }

    function panel() {
        return root.querySelector(".kw-cs");
    }

    function showBar() {
        const el = bar();

        el.hidden = false;

        // Reading the layout commits the hidden state, so the slide-in
        // transition runs even when the tab is not visible yet. A
        // requestAnimationFrame here would wait for a visible frame.
        void el.offsetHeight;
        el.classList.add("is-up");
    }

    function hideBar() {
        bar().classList.remove("is-up");

        // Keep the exit animation, then take the bar out of the layout so it
        // can never sit invisible on top of the footer links.
        setTimeout(function () {
            if (!bar().classList.contains("is-up")) bar().hidden = true;
        }, 320);
    }

    function switches() {
        return [...root.querySelectorAll(".kw-switch__input:not([disabled])")];
    }

    function reflectSwitches() {
        switches().forEach(function (input) {
            const hint = input.closest(".kw-switch").querySelector(".kw-switch__hint");

            hint.textContent = input.checked ? "On" : "Off";
        });
    }

    function prefill() {
        const values = chosen();

        root.querySelectorAll("[data-category]").forEach(function (input) {
            const category = CATEGORIES.filter(c => c.key === input.dataset.category)[0];

            input.checked = category.locked || values[category.key];
        });

        reflectSwitches();
    }

    function openPanel(trigger) {
        opener = trigger || null;

        prefill();
        panel().hidden = false;
        document.documentElement.classList.add("kw-cs-open");
        panel().querySelector(".kw-cs__dialog").focus();
    }

    function closePanel() {
        const dialog = panel().querySelector(".kw-cs__dialog");

        panel().hidden = true;
        document.documentElement.classList.remove("kw-cs-open");

        if (opener && document.contains(opener)) opener.focus();
        else if (dialog.contains(document.activeElement)) dialog.blur();

        opener = null;
    }

    function fromSwitches() {
        const categories = {};

        root.querySelectorAll("[data-category]").forEach(function (input) {
            categories[input.dataset.category] = input.checked;
        });

        return categories;
    }

    function decide(kind) {
        const all = kind === "accept";

        save({
            necessary: true,
            analytics: all || (kind === "save" && fromSwitches().analytics),
            marketing: all || (kind === "save" && fromSwitches().marketing)
        });

        closePanel();
        hideBar();
    }

    function savedNotice(text) {
        const notice = panel().querySelector("#kw-cs-saved");

        notice.textContent = text;
        notice.hidden = false;

        setTimeout(function () {
            notice.hidden = true;
        }, 2600);
    }

    function onClick(event) {
        const trigger = event.target.closest("[data-consent], [data-cookie-settings]");

        if (!trigger) return;

        const action = trigger.dataset.consent || "settings";

        if (action === "settings") {
            if (!root) mount();
            openPanel(trigger);
            return;
        }

        if (!root) return;

        if (action === "close") {
            closePanel();

            if (!current) showBar();
            return;
        }

        if (action === "save") {
            save(fromSwitches());
            prefill();
            savedNotice("Your preferences are saved.");
            hideBar();
            setTimeout(closePanel, 700);
            return;
        }

        decide(action);
    }

    function onKeydown(event) {
        if (!root || panel().hidden) return;

        if (event.key === "Escape") {
            event.preventDefault();
            closePanel();

            if (!current) showBar();
            return;
        }

        if (event.key !== "Tab") return;

        const dialog = panel().querySelector(".kw-cs__dialog");
        const focusable = [...dialog.querySelectorAll(
            "a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex='-1'])"
        )];

        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /* PUBLIC — other scripts and the footer link talk to the site through
       this object instead of reading storage themselves. */
    window.KwetuConsent = {
        version: CONFIG.version,
        categories: CATEGORIES.map(function (category) { return category.key; }),

        get: function () {
            return current ? asState(current) : null;
        },

        has: function (category) {
            return category === "necessary"
                ? true
                : Boolean(current && current.categories[category]);
        },

        open: function (trigger) {
            if (!root) mount();
            openPanel(trigger);
        },

        save: save,

        reset: function () {
            clearStored();
            current = null;

            if (!root) mount();
            else {
                closePanel();
                showBar();
            }
        }
    };

    document.addEventListener("click", onClick);
    document.addEventListener("keydown", onKeydown);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", mount, { once: true });
    } else {
        mount();
    }

    publish();
})();
