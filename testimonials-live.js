/* ========================================
   KWETU CREATIONS — APPROVED TESTIMONIALS
   =========================================

   Fills the testimonial cards on the homepage carousel and on
   testimonials.html with the entries a person approved in admin/review.php,
   and turns them over one at a time inside the review box on the About page —
   all from the same source.

   Two rules this script never bends:

   1. Client-typed text is placed with textContent, never as markup. A review
      containing "<script>" stays a review containing the letters <script>.

   2. The number of stars drawn is the number the client chose. Nothing here
      can manufacture a five that nobody awarded.

   If the feed is missing, unreachable, or holds nothing approved, the
   placeholder cards on the page are left exactly as they are. Those cards
   admit they are placeholders, which is the honest state, so replacing them
   with silence would be a downgrade.
======================================== */

(function () {
    "use strict";

    var FEED = "backend/approved-testimonials.php";

    function stars(rating) {
        var filled = Math.max(0, Math.min(5, rating));
        return "★".repeat(filled) + "☆".repeat(5 - filled);
    }

    // Reads the feed's own address out of the page so a subfolder install
    // needs no code change, only the data attribute on the script tag.
    function feedUrl() {
        var tag = document.querySelector("script[data-testimonial-feed]");
        return tag ? tag.getAttribute("data-testimonial-feed") : FEED;
    }

    // A page that wants the approved reviews to turn over inside one box,
    // rather than show the whole set at once, opts in with an empty
    // <div data-featured-review>. The order is the rule that used to pick the
    // single review: most stars first, and where two agree, the fuller answer,
    // so a one-word approval can never outvote a paragraph of detail.
    var FEATURED_ROTATE_MS = 6000;

    function featuredOrder(items) {
        return items.slice().sort(function (a, b) {
            if (a.rating !== b.rating) { return b.rating - a.rating; }
            return b.text.length - a.text.length;
        });
    }

    function startFeatured(root, items) {
        var quote = root.querySelector(".about-review-quote");
        var caption = root.querySelector(".about-review-who");
        if (!quote || !caption) { return; }

        var dotsWrap = root.querySelector("[data-featured-dots]");
        var status = root.querySelector("[data-featured-status]");
        var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
        var order = featuredOrder(items);
        var index = 0;
        var timer = null;
        var dots = [];
        // Hover, keyboard focus and a hidden tab can each want the rotation
        // held; counting the reasons means leaving one cannot switch the timer
        // back on while another still holds it.
        var pauses = new Set();

        function show(position) {
            var item = order[position];

            quote.textContent = "“" + item.text + "”";

            var name = document.createElement("strong");
            name.textContent = item.name;

            var detail = document.createElement("span");
            detail.textContent = item.business || "Client";

            caption.textContent = "";
            caption.append(name, detail);

            dots.forEach(function (dot, which) {
                if (which === position) {
                    dot.setAttribute("aria-current", "true");
                } else {
                    dot.removeAttribute("aria-current");
                }
            });

            if (status) {
                status.textContent = "Showing review " + (position + 1)
                    + " of " + order.length;
            }
        }

        function buildDots() {
            if (!dotsWrap || order.length < 2) { return; }
            dotsWrap.textContent = "";
            dots = [];
            order.forEach(function (item, position) {
                var dot = document.createElement("button");
                dot.type = "button";
                dot.className = "about-review-dot";
                dot.setAttribute("aria-label", "Show the review from " + item.name);
                dot.addEventListener("click", function () {
                    index = position;
                    show(index);
                    start();
                });
                dotsWrap.appendChild(dot);
                dots.push(dot);
            });
        }

        function stop() {
            if (!timer) { return; }
            clearInterval(timer);
            timer = null;
        }

        function start() {
            stop();
            if (order.length < 2 || pauses.size > 0 || reducedMotion.matches) {
                return;
            }
            timer = setInterval(function () {
                // Hold still while the box is scrolled out of view instead of
                // cycling through reviews nobody is looking at.
                var box = root.getBoundingClientRect();
                if (box.bottom <= 0 || box.top >= window.innerHeight) { return; }
                index = index + 1 < order.length ? index + 1 : 0;
                show(index);
            }, FEATURED_ROTATE_MS);
        }

        function setPause(reason, on) {
            if (on) { pauses.add(reason); } else { pauses.delete(reason); }
            if (on) { stop(); } else { start(); }
        }

        buildDots();
        show(index);

        root.addEventListener("mouseenter", function () { setPause("hover", true); });
        root.addEventListener("mouseleave", function () { setPause("hover", false); });
        root.addEventListener("focusin", function () { setPause("focus", true); });
        root.addEventListener("focusout", function () { setPause("focus", false); });
        document.addEventListener("visibilitychange", function () {
            setPause("hidden", document.hidden);
        });
        reducedMotion.addEventListener("change", function () { start(); });
    }

    function makeGridCard(item) {
        var card = document.createElement("article");
        card.className = "testimonial-card";

        var mark = document.createElement("div");
        mark.className = "testimonial-quote";
        mark.setAttribute("aria-hidden", "true");
        mark.textContent = "“";

        var rating = document.createElement("div");
        rating.className = "testimonial-stars";
        rating.setAttribute("aria-label", item.rating + " out of 5 stars");
        rating.textContent = stars(item.rating);

        var quote = document.createElement("blockquote");
        quote.textContent = item.text;

        var who = document.createElement("div");
        who.className = "testimonial-client";

        var name = document.createElement("strong");
        name.textContent = item.name;

        var org = document.createElement("span");
        org.textContent = item.business || "Client";

        who.append(name, org);
        card.append(mark, rating, quote, who);

        return card;
    }

    function makeCarouselCard(item) {
        var card = document.createElement("article");
        card.className = "testimonial-card";

        var rating = document.createElement("div");
        rating.className = "testimonial-stars";
        rating.setAttribute("aria-label", item.rating + " out of 5 stars");
        rating.textContent = stars(item.rating);

        var quote = document.createElement("p");
        quote.className = "testimonial-quote";
        quote.textContent = item.text;

        var who = document.createElement("div");
        who.className = "testimonial-client";

        var name = document.createElement("h3");
        name.textContent = item.name;

        var org = document.createElement("p");
        org.textContent = item.business || "Client";

        who.append(name, org);
        card.append(rating, quote, who);

        return card;
    }

    function fill(container, items, build) {
        container.textContent = "";
        items.forEach(function (item) { container.appendChild(build(item)); });
    }

    function looksValid(item) {
        return item && typeof item.name === "string" && item.name !== ""
            && typeof item.text === "string" && item.text !== ""
            && Number.isFinite(item.rating);
    }

    function load() {
        fetch(feedUrl(), { credentials: "same-origin" })
            .then(function (response) {
                if (!response.ok) throw new Error("feed status " + response.status);
                return response.json();
            })
            .then(function (data) {
                var items = (data.testimonials || []).filter(looksValid);

                if (!items.length) return;

                var grid = document.querySelector(".testimonials-grid");
                var track = document.getElementById("testimonials-track");
                var changed = false;

                if (grid) {
                    fill(grid, items, makeGridCard);
                    changed = true;
                }

                if (track) {
                    fill(track, items, makeCarouselCard);
                    changed = true;
                }

                // The single-box area needs no carousel resize event, and a
                // page with no approved review keeps the placeholder it ships
                // with rather than showing an empty box.
                var featured = document.querySelector("[data-featured-review]");
                if (featured) { startFeatured(featured, items); }

                // The carousel measures its own cards once, at startup. Tell it
                // the cards changed underneath, otherwise the new set keeps the
                // old group width and the track slides to the wrong distance.
                if (changed) {
                    document.dispatchEvent(new CustomEvent("kwetu:testimonials-updated"));
                }
            })
            .catch(function () {
                // No backend here — a plain static host, or the feed file
                // missing. The placeholder cards stay, and they say so.
            });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", load);
    } else {
        load();
    }
})();
