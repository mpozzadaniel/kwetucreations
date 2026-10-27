
const scriptElement = document.currentScript;

const sharedFilesBase = new URL(
    ".",
    scriptElement ? scriptElement.src : window.location.href
);

document.addEventListener("DOMContentLoaded", async function () {
    const header = document.getElementById("site-header");
    const footer = document.getElementById("site-footer");

    async function loadSharedFile(element, filePath) {
        if (!element) {
            console.warn("Missing page placeholder for:", filePath);
            return;
        }

        // tools/bake-chrome.php writes the real navigation and footer into the
        // slot so that the links exist in the HTML before any script runs. A
        // slot that already holds an element is left exactly as it is -
        // fetching here would replace markup the crawler already saw with the
        // same markup again, and a page would pay for a request it did not
        // need. Comments alone do not count, so a page returned to an empty
        // slot by --unset still loads its chrome the way it always did.
        if (element.firstElementChild) {
            return;
        }

        const fileURL = new URL(filePath, sharedFilesBase);
        const response = await fetch(fileURL);

        if (!response.ok) {
            throw new Error(
                "Failed to load " + filePath + ": " + response.status
            );
        }

        element.innerHTML = await response.text();
    }

    try {
        await loadSharedFile(
            header,
            "includes/header.html"
        );

        // The bar is fixed to the top of the viewport, so pages publish its
        // measured height as --nav-h and start their own content below it.
        if (header) {
            function publishNavHeight() {
                const bar = header.querySelector(".navbar") ||
                    header.firstElementChild;
                const height = bar
                    ? Math.round(bar.getBoundingClientRect().height)
                    : 0;

                if (height > 0) {
                    document.documentElement.style.setProperty(
                        "--nav-h",
                        `${height}px`
                    );
                }
            }

            publishNavHeight();
            window.addEventListener("resize", publishNavHeight);

            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(publishNavHeight);
            }
        }

        await loadSharedFile(
            footer,
            "includes/footer.html"
        );

        const year = document.getElementById("year");

        if (year) {
            year.textContent = new Date().getFullYear();
        }

        const menuToggle = document.getElementById("menu-toggle");
        const navigation = document.getElementById("main-navigation");

        if (menuToggle && navigation) {
            function closeMenu() {
                navigation.classList.remove("is-open");
                menuToggle.setAttribute("aria-expanded", "false");
                menuToggle.setAttribute(
                    "aria-label",
                    "Open navigation menu"
                );
            }

            menuToggle.addEventListener("click", function () {
                const isOpen =
                    menuToggle.getAttribute("aria-expanded") === "true";

                navigation.classList.toggle("is-open", !isOpen);

                menuToggle.setAttribute(
                    "aria-expanded",
                    String(!isOpen)
                );

                menuToggle.setAttribute(
                    "aria-label",
                    isOpen
                        ? "Open navigation menu"
                        : "Close navigation menu"
                );
            });

            navigation.querySelectorAll("a").forEach(function (link) {
                link.addEventListener("click", closeMenu);
            });

            document.addEventListener("click", function (event) {
                if (
                    header &&
                    !header.contains(event.target) &&
                    navigation.classList.contains("is-open")
                ) {
                    closeMenu();
                }
            });

            document.addEventListener("keydown", function (event) {
                if (event.key === "Escape") {
                    closeMenu();
                }
            });

            window.addEventListener("resize", function () {
                if (window.innerWidth > 760) {
                    closeMenu();
                }
            });
        }

        console.log("Shared navigation and footer loaded successfully.");

    } catch (error) {
        console.error("Shared content error:", error);
    }
});
/* Automatically highlight the current navigation page */
function setActiveNavigation() {
    const raw = window.location.pathname.split("/").pop();
    // The site's home is index3.html, reached as "/", "/index3.html",
    // "/index.html" or "/home.html" depending on host and entry point.
    const currentPage = (!raw || raw === "index.html" || raw === "home.html")
        ? "index3.html"
        : raw;

    const pageLinks = document.querySelectorAll(
        '#site-header a[data-page], #site-footer a[data-page]'
    );

    pageLinks.forEach(function (link) {
        const isCurrentPage = link.getAttribute("data-page") === currentPage;

        link.classList.toggle("active", isCurrentPage);

        if (isCurrentPage) {
            link.setAttribute("aria-current", "page");
        } else {
            link.removeAttribute("aria-current");
        }
    });
}

/* Run now and again after the shared header loads */
document.addEventListener("DOMContentLoaded", function () {
    setActiveNavigation();

    const headerContainer = document.getElementById("site-header");

    if (headerContainer) {
        const observer = new MutationObserver(setActiveNavigation);
        observer.observe(headerContainer, {
            childList: true,
            subtree: true
        });
    }
});
