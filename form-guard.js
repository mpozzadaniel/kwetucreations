/* ========================================
   KWETU CREATIONS — ENQUIRY FORM GUARD
   =========================================

   The enquiry forms on this site have no server of their own unless one is
   given to them, so this script does three jobs and nothing else:

   1. Validate every field in the browser and show the problem next to the
      field it belongs to, instead of one blocking alert() for the whole form.

   2. Be honest when the form is otherwise ready and has no server. A filled-in
      form must never say "thank you, we received it" while nothing was sent.
      Instead the enquiry stays on screen and the visitor is offered a
      prefilled email link and a copy button, so the typing is never wasted.

   3. If the form carries data-endpoint, send it there instead, and only claim
      it was saved once the server says it was. When that endpoint is missing
      or unreachable, this falls back to job 2 unchanged — the same file works
      on a hosted backend and on plain static hosting.

   To connect a real backend: give the form data-endpoint (and
   data-csrf-endpoint if the endpoint wants a token), and delete the
   data-form-guard attribute only if you want the browser to submit the old
   way and this script to step aside.
======================================== */

(function () {
    "use strict";

    const forms = document.querySelectorAll("form[data-form-guard]");

    function labelFor(field) {
        const label = field.id && document.querySelector('label[for="' + field.id + '"]');
        const text = label
            ? label.textContent
            : field.getAttribute("aria-label") || field.name || "This field";

        return text.replace(/[*•]/g, "").replace(/\(optional\)/i, "").trim().replace(/\s+/g, " ");
    }

    // One message paragraph per field, inserted into the same block that
    // already holds the control, so it inherits that block's spacing.
    function messageElement(field) {
        if (field.__guardMessage) return field.__guardMessage;

        const host = field.closest(".field, .form-group, .consent-group, .upload-box") || field.parentElement;
        const id = (field.id || field.name || "field") + "-message";

        let paragraph = host.querySelector("#" + CSS.escape(id));

        if (!paragraph) {
            paragraph = document.createElement("p");
            paragraph.className = "field-message";
            paragraph.id = id;
            host.appendChild(paragraph);
        }

        const described = field.getAttribute("aria-describedby");

        field.setAttribute(
            "aria-describedby",
            described ? described + " " + id : id
        );

        field.__guardMessage = paragraph;

        return paragraph;
    }

    function problem(field) {
        const v = field.validity;

        // Long consent sentences make unreadable error text, so a field may
        // carry a short name for messages while the enquiry keeps the label.
        const name = field.getAttribute("data-guard-label") || labelFor(field);

        if (v.valueMissing) return name + " is required.";
        if (v.typeMismatch) return "Enter a valid email address, like name@example.com.";
        if (v.badInput) return name + " does not look right.";
        if (v.patternMismatch) return field.getAttribute("title") || name + " is not in the expected format.";
        if (v.tooShort) return "Please write at least " + field.minLength + " characters.";
        if (v.tooLong) return "Please keep this under " + field.maxLength + " characters.";

        return "";
    }

    function showProblem(field, text) {
        const paragraph = messageElement(field);

        field.setAttribute("aria-invalid", text ? "true" : "false");
        paragraph.textContent = text;
        paragraph.classList.toggle("is-visible", !!text);
    }

    // Bot traps and handshake values are carried in the form but are never a
    // thing a person can be wrong about, so they skip validation, skip the
    // per-field message slots, and skip the email/copy summary.
    const INTERNAL = ["company_website", "csrf_token", "form_started_at"];

    function fields(form) {
        return [...form.querySelectorAll("input, select, textarea")]
            .filter(field => field.type !== "hidden")
            .filter(field => INTERNAL.indexOf(field.name) === -1);
    }

    function statusElement(form) {
        const existing = form.querySelector("[data-form-status]");

        if (existing) return existing;

        const paragraph = document.createElement("p");

        paragraph.className = "form-status";
        paragraph.setAttribute("data-form-status", "");
        paragraph.setAttribute("role", "alert");
        paragraph.setAttribute("aria-live", "polite");
        paragraph.tabIndex = -1;

        const button = form.querySelector("button[type='submit'], .submit");

        if (button && button.parentElement) button.parentElement.appendChild(paragraph);
        else form.appendChild(paragraph);

        return paragraph;
    }

    // Plain text version of the enquiry, so the visitor can send it by hand
    // and nothing is lost when there is no server to receive it.
    function summary(form) {
        const lines = fields(form).map(field => {
            const name = labelFor(field);

            if (field.type === "file") {
                const files = [...field.files];

                return files.length ? name + ": " + files.map(f => f.name).join(", ") : "";
            }

            if (field.type === "checkbox") {
                return field.checked ? name + ": yes" : "";
            }

            const value = field.value.trim();

            return value ? name + ": " + value : "";
        });

        return lines.filter(Boolean);
    }

    function actions(form, status, lines, why) {
        status.textContent = "";

        const honest = document.createElement("strong");

        honest.textContent = why || "This form could not send your message just now.";

        const advice = document.createElement("span");

        advice.textContent = " Your answers are still in the form. Send them directly, or copy the text and paste it into WhatsApp or an email.";

        status.appendChild(honest);
        status.appendChild(advice);

        const row = document.createElement("span");

        row.className = "form-actions";

        const subject = form.getAttribute("data-subject") || "Website enquiry";
        const body = lines.join("\r\n");
        const link = document.createElement("a");

        link.className = "form-action";
        link.textContent = "Email this to us";
        link.href = "mailto:" + (form.getAttribute("data-recipient") || "") +
            "?subject=" + encodeURIComponent(subject) +
            "&body=" + encodeURIComponent(body.slice(0, 1800));

        const copy = document.createElement("button");

        copy.type = "button";
        copy.className = "form-action";
        copy.textContent = "Copy the text";

        copy.addEventListener("click", function () {
            const done = function () {
                copy.textContent = "Copied";
                setTimeout(function () { copy.textContent = "Copy the text"; }, 3000);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(body).then(done, function () { fallback(body, done); });
            } else {
                fallback(body, done);
            }
        });

        row.appendChild(link);
        row.appendChild(copy);
        status.appendChild(row);
    }

    function fallback(text, done) {
        const area = document.createElement("textarea");

        area.value = text;
        area.setAttribute("aria-hidden", "true");
        area.style.position = "fixed";
        area.style.opacity = "0";
        document.body.appendChild(area);
        area.select();

        try {
            document.execCommand("copy");
            done();
        } catch (error) {
            // Clipboard access can be refused; the mailto link above still
            // carries the same text, so the enquiry is not lost.
        }

        area.remove();
    }

    /* ========================================
       OPTIONAL SERVER PATH
       =========================================

       A form carrying data-endpoint is stored for review instead of handed
       back to the visitor. Two things are non-negotiable here:

       - "thank you" is only ever said after the server has answered 201 with
         saved:true. Any other outcome keeps the visitor's typing on screen
         and tells them the truth about what happened.
       - if there is no server at all (the site is on plain static hosting, or
         the backend folder was not deployed) the request fails, and this
         falls through to the same email-and-copy handoff as before, so the
         form is never worse than it was yesterday.
    ========================================= */

    function urlFor(form, name) {
        const anchor = document.createElement("a");

        anchor.href = form.getAttribute(name) || "";

        return anchor.href;
    }

    function busy(form, on) {
        const button = form.querySelector("button[type='submit'], .submit");

        if (!button) return;

        button.disabled = on;
        button.textContent = on ? "Sending…" : button.dataset.idleLabel || button.textContent;
    }

    function payloadFor(form, token) {
        const data = new FormData(form);

        data.set("form_started_at", String(form.__openedAt || Math.floor(Date.now() / 1000)));

        if (token) data.set("csrf_token", token);

        return data;
    }

    function askToken(form) {
        const endpoint = form.getAttribute("data-csrf-endpoint");

        if (!endpoint) return Promise.resolve("");

        return fetch(urlFor(form, "data-csrf-endpoint"), { credentials: "same-origin" })
            .then(function (response) {
                if (!response.ok) throw new Error("token endpoint returned " + response.status);
                return response.json();
            })
            .then(function (json) {
                if (!json || typeof json.token !== "string") throw new Error("token endpoint sent no token");
                return json.token;
            });
    }

    function sendOnce(form, token) {
        return fetch(urlFor(form, "data-endpoint"), {
            method: "POST",
            credentials: "same-origin",
            headers: { "Accept": "application/json" },
            body: payloadFor(form, token)
        }).then(function (response) {
            // An error page from Apache is HTML, not JSON. Reading it as text
            // first means a missing backend looks like a missing backend
            // rather than like a broken submission.
            return response.text().then(function (text) {
                let parsed = {};

                try {
                    parsed = JSON.parse(text);
                } catch (notJson) {
                    parsed = {};
                }

                return { code: response.status, ok: response.ok, data: parsed };
            });
        });
    }

    function say(status, text, kind) {
        status.textContent = "";

        const line = document.createElement("strong");

        line.textContent = text;
        status.appendChild(line);

        if (kind) status.className = "form-status " + kind;
    }

    function markSaved(form, status, data) {
        const message = data.message || "Thank you for your feedback!";

        say(status, message, "is-saved");
        form.reset();
        form.__openedAt = Math.floor(Date.now() / 1000);

        // The second line, when the endpoint sends one. Kept separate from the
        // headline so the box reads as a reply, not as one long sentence.
        if (data.detail) {
            const detail = document.createElement("span");

            detail.className = "form-status-detail";
            detail.textContent = data.detail;
            status.appendChild(detail);
        }

        // Only for the fallback wording, which does not explain itself. An
        // endpoint that returns its own message has already said this.
        if (!data.message) {
            const note = document.createElement("span");

            note.textContent = " You do not need to send it again, and nothing is published automatically.";
            status.appendChild(note);
        }
    }

    function applyServerErrors(form, status, map, reason) {
        let firstBroken = null;

        status.textContent = "";

        Object.keys(map).forEach(function (name) {
            // A server that names the field it disliked may name it without the
            // "[]" a multiple-value input carries in the form itself.
            const field = form.querySelector('[name="' + name + '"]')
                || form.querySelector('[name="' + name + '[]"]');

            if (!field) return;

            field.dataset.touched = "1";
            showProblem(field, map[name]);

            if (!firstBroken) firstBroken = field;
        });

        say(status, reason || "Some answers still need attention.", "is-error");

        if (firstBroken) firstBroken.focus();
    }

    function submitToServer(form, status, lines) {
        busy(form, true);

        askToken(form)
            .catch(function () { return ""; })
            .then(function (token) {
                return sendOnce(form, token);
            })
            .then(function (result) {
                // An expired session is worth one silent retry; anything else
                // is reported as it is.
                if (result.code === 403 && !result.data.field) return result;

                if (result.code === 403) {
                    return askToken(form)
                        .catch(function () { return ""; })
                        .then(function (fresh) { return sendOnce(form, fresh); });
                }

                return result;
            })
            .then(function (result) {
                busy(form, false);

                if (result.code === 201 && result.data.saved === true) {
                    markSaved(form, status, result.data);
                    return;
                }

                if (result.code === 422 && result.data.fields) {
                    applyServerErrors(form, status, result.data.fields, result.data.reason);
                    return;
                }

                if (result.data && result.data.reason) {
                    say(status, result.data.reason, "is-error");
                    return;
                }

                actions(
                    form,
                    status,
                    lines,
                    "The online form did not answer, so nothing has been submitted here."
                );
            })
            .catch(function () {
                busy(form, false);

                actions(
                    form,
                    status,
                    lines,
                    "The online form could not be reached just now, so nothing has been submitted here."
                );
            });
    }

    forms.forEach(function (form) {
        form.setAttribute("novalidate", "");
        form.__openedAt = Math.floor(Date.now() / 1000);

        // The button's own words are the only correct thing to restore after
        // "Sending…" — each form labels its button differently.
        const submitButton = form.querySelector("button[type='submit'], .submit");

        if (submitButton) submitButton.dataset.idleLabel = submitButton.textContent.trim();

        fields(form).forEach(function (field) {
            messageElement(field);

            field.addEventListener("blur", function () {
                if (field.dataset.touched) showProblem(field, problem(field));
            });

            field.addEventListener("input", function () {
                if (field.dataset.touched && !field.validity.valueMissing) {
                    showProblem(field, problem(field));
                }
            });
        });

        form.addEventListener("submit", function (event) {
            // There is no backend. The browser must never navigate away from
            // this page, whatever the form's action attribute says.
            event.preventDefault();

            const list = fields(form);
            let firstBroken = null;
            let count = 0;

            list.forEach(function (field) {
                const text = problem(field);

                field.dataset.touched = "1";
                showProblem(field, text);

                if (text) {
                    count++;
                    if (!firstBroken) firstBroken = field;
                }
            });

            const status = statusElement(form);

            if (count) {
                status.textContent = count === 1
                    ? "One answer still needs attention."
                    : count + " answers still need attention.";
                firstBroken.focus();
                return;
            }

            if (form.hasAttribute("data-endpoint")) {
                submitToServer(form, status, summary(form));
            } else {
                actions(form, status, summary(form));
            }
        });
    });
})();
