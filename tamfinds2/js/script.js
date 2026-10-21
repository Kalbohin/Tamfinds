// Tiny JS for the TamFinds pages. Each part only runs if its element exists on the page.

// 1) Show / hide password (the eye button)
function togglePassword(btn) {
    var input = btn.parentElement.querySelector("input");
    var isHidden = input.type === "password";
    input.type = isHidden ? "text" : "password";
    btn.classList.toggle("showing", isHidden);   // CSS hides the slash line on the eye
}

// 2) Verification code boxes: jump to the next box, backspace goes back, paste a full code
var boxes = document.querySelectorAll(".otp input");

boxes.forEach(function (box, i) {
    box.addEventListener("input", function () {
        box.value = box.value.replace(/\D/g, "");          // numbers only
        if (box.value !== "" && i < boxes.length - 1) {
            boxes[i + 1].focus();
        }
    });

    box.addEventListener("keydown", function (e) {
        if (e.key === "Backspace" && box.value === "" && i > 0) {
            boxes[i - 1].focus();
        }
    });

    box.addEventListener("paste", function (e) {
        e.preventDefault();
        var digits = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, boxes.length);
        for (var j = 0; j < digits.length; j++) {
            boxes[j].value = digits[j];
        }
        if (digits.length > 0) {
            boxes[Math.min(digits.length, boxes.length - 1)].focus();
        }
    });
});

// 3) "Resend after 40 seconds" countdown
var resendForm = document.querySelector(".resend");

if (resendForm) {
    var secondsLeft = parseInt(resendForm.dataset.seconds, 10);
    var resendText = document.getElementById("resendText");
    var resendBtn = document.getElementById("resendBtn");

    function tick() {
        if (secondsLeft > 0) {
            resendText.textContent = "Resend after " + secondsLeft + " seconds";
            secondsLeft--;
            setTimeout(tick, 1000);
        } else {
            resendText.hidden = true;
            resendBtn.hidden = false;
        }
    }
    tick();
}

// 4) Navbar: close the Items dropdown when clicking elsewhere or pressing Esc
var itemsMenu = document.querySelector(".dropdown");

if (itemsMenu) {
    document.addEventListener("click", function (e) {
        if (!itemsMenu.contains(e.target)) {
            itemsMenu.removeAttribute("open");
        }
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            itemsMenu.removeAttribute("open");
        }
    });
}

// 5) Phone menu (hamburger)
var navToggle = document.getElementById("navToggle");
var mainNav = document.getElementById("mainNav");

function setNav(open) {
    if (!navToggle || !mainNav) { return; }
    mainNav.classList.toggle("is-open", open);
    navToggle.setAttribute("aria-expanded", open ? "true" : "false");
    navToggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
}

if (navToggle && mainNav) {
    navToggle.addEventListener("click", function (e) {
        e.stopPropagation();
        setNav(!mainNav.classList.contains("is-open"));
    });
    // tap anywhere else, or press Esc, to close it
    document.addEventListener("click", function (e) {
        if (!mainNav.contains(e.target) && !navToggle.contains(e.target)) {
            setNav(false);
        }
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { setNav(false); }
    });
}

// 6) Log out confirmation pop-up
var logoutLink = document.getElementById("logoutLink");
var logoutModal = document.getElementById("logout-modal");

if (logoutLink && logoutModal) {
    var logoutCancel = document.getElementById("logoutCancel");
    var logoutConfirm = document.getElementById("logoutConfirm");
    var logoutBackdrop = document.getElementById("logoutBackdrop");

    var openLogout = function () {
        setNav(false);                                   // close the phone menu behind it
        logoutModal.classList.add("is-open");
        document.body.classList.add("modal-open");       // stop the page scrolling behind it
        logoutCancel.focus();
    };

    var closeLogout = function () {
        logoutModal.classList.remove("is-open");
        document.body.classList.remove("modal-open");
        if (window.location.hash === "#logout-modal") {
            history.replaceState(null, "", window.location.pathname + window.location.search);
        }
    };

    logoutLink.addEventListener("click", function (e) {
        e.preventDefault();          // don't log out yet, ask first
        openLogout();
    });
    logoutCancel.addEventListener("click", function (e) { e.preventDefault(); closeLogout(); });
    logoutBackdrop.addEventListener("click", function (e) { e.preventDefault(); closeLogout(); });

    document.addEventListener("keydown", function (e) {
        if (!logoutModal.classList.contains("is-open")) { return; }
        if (e.key === "Escape") { closeLogout(); }
        if (e.key === "Tab") {                           // keep Tab inside the pop-up
            if (e.shiftKey && document.activeElement === logoutCancel) {
                e.preventDefault(); logoutConfirm.focus();
            } else if (!e.shiftKey && document.activeElement === logoutConfirm) {
                e.preventDefault(); logoutCancel.focus();
            }
        }
    });
}

// 7) Home page carousel: moves to the next card every 3 seconds, loops back after the last one.
//    Also works with swipe, the arrow buttons and the pause button.
var carousel = document.querySelector("[data-carousel]");

if (carousel) {
    var track = carousel.querySelector(".carousel-track");
    var prevBtn = carousel.querySelector("[data-carousel-prev]");
    var nextBtn = carousel.querySelector("[data-carousel-next]");
    var playBtn = carousel.querySelector("[data-carousel-play]");
    var thumb = carousel.querySelector(".carousel-thumb");

    var INTERVAL = 3000;      // 3 seconds per card
    var timer = null;

    // people who asked their device for "reduce motion" start paused (they can press play)
    var userPaused = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
    var behavior = userPaused ? "auto" : "smooth";

    var maxScroll = function () { return track.scrollWidth - track.clientWidth; };

    // the cards can be replaced by the live update, so look them up each time
    var getSlides = function () { return Array.prototype.slice.call(track.querySelectorAll(".slide")); };

    // where each card starts inside the track
    var positions = function () {
        var list = getSlides();
        if (list.length === 0) { return []; }
        var base = list[0].offsetLeft;
        return list.map(function (slide) { return slide.offsetLeft - base; });
    };

    // which card is closest to the left edge right now
    var currentIndex = function () {
        var pos = positions();
        var best = 0;
        for (var i = 1; i < pos.length; i++) {
            if (Math.abs(pos[i] - track.scrollLeft) < Math.abs(pos[best] - track.scrollLeft)) { best = i; }
        }
        return best;
    };

    var moveTo = function (x) {
        if (track.scrollTo) { track.scrollTo({ left: x, behavior: behavior }); }
        else { track.scrollLeft = x; }
    };

    var goNext = function () {
        var pos = positions();
        if (pos.length === 0) { return; }
        var max = maxScroll();
        if (track.scrollLeft >= max - 4) { moveTo(0); return; }          // reached the end: back to the first card
        moveTo(Math.min(pos[Math.min(currentIndex() + 1, pos.length - 1)], max));
    };

    var goPrev = function () {
        var pos = positions();
        if (pos.length === 0) { return; }
        var max = maxScroll();
        if (track.scrollLeft <= 4) { moveTo(max); return; }              // at the start: jump to the last card
        moveTo(pos[Math.max(currentIndex() - 1, 0)]);
    };

    var stop = function () {
        if (timer) { clearInterval(timer); timer = null; }
    };
    var start = function () {
        stop();
        if (!userPaused) { timer = setInterval(goNext, INTERVAL); }
    };

    var updatePlayButton = function () {
        playBtn.classList.toggle("is-paused", userPaused);
        playBtn.setAttribute("aria-pressed", userPaused ? "true" : "false");
        playBtn.setAttribute("aria-label", userPaused ? "Play auto-scroll" : "Pause auto-scroll");
        track.setAttribute("aria-live", userPaused ? "polite" : "off");
    };

    // the thin bar under the cards
    var updateProgress = function () {
        var total = track.scrollWidth;
        if (!total) { return; }
        thumb.style.width = (track.clientWidth / total * 100) + "%";
        thumb.style.left = (track.scrollLeft / total * 100) + "%";
    };

    nextBtn.addEventListener("click", function () { goNext(); start(); });
    prevBtn.addEventListener("click", function () { goPrev(); start(); });

    playBtn.addEventListener("click", function () {
        userPaused = !userPaused;
        updatePlayButton();
        start();
    });

    // pause while the mouse is over it, a finger is on it, or keyboard focus is inside it
    carousel.addEventListener("pointerenter", function (e) { if (e.pointerType === "mouse") { stop(); } });
    carousel.addEventListener("pointerleave", function (e) { if (e.pointerType === "mouse") { start(); } });
    carousel.addEventListener("touchstart", stop, { passive: true });
    carousel.addEventListener("touchend", start, { passive: true });
    carousel.addEventListener("focusin", stop);
    carousel.addEventListener("focusout", function (e) {
        if (!carousel.contains(e.relatedTarget)) { start(); }
    });

    // don't keep scrolling while the tab is hidden
    document.addEventListener("visibilitychange", function () {
        if (document.hidden) { stop(); } else { start(); checkFeed(); }
    });

    track.addEventListener("scroll", updateProgress, { passive: true });
    window.addEventListener("resize", updateProgress);
    window.addEventListener("load", updateProgress);

    // ----- Live updates -----
    // Every few seconds, ask dashboard.php for the 5 newest reports. If something changed (a new report,
    // an edit, a deleted one) the cards are swapped and the newest one is back on the first card.
    var feedUrl = carousel.getAttribute("data-feed");
    var feedSig = carousel.getAttribute("data-sig");
    var feedBusy = false;

    var checkFeed = function () {
        if (!feedUrl || feedBusy || document.hidden || !window.fetch) { return; }
        feedBusy = true;

        fetch(feedUrl, { credentials: "same-origin", cache: "no-store" })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (!data || typeof data.html !== "string" || data.sig === feedSig) { return; }
                feedSig = data.sig;
                track.innerHTML = data.html;
                track.scrollLeft = 0;
                track.classList.remove("is-fresh");
                void track.offsetWidth;                       // restart the little fade-in
                track.classList.add("is-fresh");
                updateProgress();
            })
            .catch(function () { /* offline or logged out: try again next time */ })
            .then(function () { feedBusy = false; });
    };

    if (feedUrl) { setInterval(checkFeed, 10000); }

    updatePlayButton();
    updateProgress();
    start();
}

// 8) Back button protection.
// Sometimes the browser shows a saved copy of a page when you press Back instead of asking the server.
// If that happens on a logged-in page (or the login page), reload it so PHP checks the login again:
// after logging out you land on the login page, and after logging in the login page sends you home.
window.addEventListener("pageshow", function (e) {
    var needsCheck = document.getElementById("logoutLink") || document.body.hasAttribute("data-reload-on-back");

    if (e.persisted && needsCheck) {
        document.documentElement.style.visibility = "hidden";   // don't flash the old page
        window.location.reload();
    }
});

// 9) Report page: report form, edit / delete, comments, replies, "read more", photo viewer, filter panel.
//    Wrapped in a function so its variables don't clash with the code above.
(function () {
    if (!document.querySelector("[data-report-page]")) { return; }

    // ----- Report form (opens right on the page) -----
    var panel = document.getElementById("report-form");
    var form = panel ? panel.querySelector("form") : null;
    var desc = document.getElementById("description");
    var counter = document.getElementById("descCount");
    var picture = document.getElementById("picture");
    var pictureHint = document.getElementById("pictureHint");
    var pictureHintText = pictureHint ? pictureHint.textContent : "";

    // "123 / 500" under the description
    var updateCounter = function () {
        if (!desc || !counter) { return; }
        var max = parseInt(counter.getAttribute("data-max"), 10);
        counter.textContent = desc.value.length + " / " + max;
        counter.classList.toggle("is-near", desc.value.length >= max - 40);
    };
    if (desc) {
        desc.addEventListener("input", updateCounter);
        updateCounter();
    }

    // photo too big? say so right away (the server checks again anyway)
    if (picture && pictureHint) {
        picture.addEventListener("change", function () {
            var max = parseInt(picture.getAttribute("data-max-bytes"), 10);
            var chosen = picture.files && picture.files[0];
            if (chosen && chosen.size > max) {
                pictureHint.textContent = "That photo is larger than 5 MB. Please choose a smaller one.";
                pictureHint.classList.add("is-error");
                picture.value = "";
            } else {
                pictureHint.textContent = pictureHintText;
                pictureHint.classList.remove("is-error");
            }
        });
    }

    // "Edit report" forms inside the cards: their own description counter + photo size warning
    var editForms = document.querySelectorAll(".edit-report");

    var refreshEditForm = function (f) {
        var area = f.querySelector("textarea[data-counter-for]");
        var count = area ? document.getElementById(area.getAttribute("data-counter-for")) : null;
        if (area && count) {
            var max = parseInt(count.getAttribute("data-max"), 10);
            count.textContent = area.value.length + " / " + max;
            count.classList.toggle("is-near", area.value.length >= max - 40);
        }
        var hint = f.querySelector(".hint-sm");
        if (hint) {
            if (hint.getAttribute("data-original") !== null) { hint.textContent = hint.getAttribute("data-original"); }
            hint.classList.remove("is-error");
        }
    };

    Array.prototype.forEach.call(editForms, function (f) {
        var area = f.querySelector("textarea[data-counter-for]");
        var file = f.querySelector("input[type=file]");
        var hint = f.querySelector(".hint-sm");
        if (hint) { hint.setAttribute("data-original", hint.textContent); }

        if (area) { area.addEventListener("input", function () { refreshEditForm(f); }); }
        if (file && hint) {
            file.addEventListener("change", function () {
                var max = parseInt(file.getAttribute("data-max-bytes"), 10);
                var chosen = file.files && file.files[0];
                if (chosen && chosen.size > max) {
                    hint.textContent = "That photo is larger than 5 MB. Please choose a smaller one.";
                    hint.classList.add("is-error");
                    file.value = "";
                } else {
                    hint.textContent = hint.getAttribute("data-original");
                    hint.classList.remove("is-error");
                }
            });
        }
    });

    // a card that opens already in edit mode (the last save had an error): bring it into view
    var editingCard = document.querySelector(".report-card.is-editing");
    if (editingCard && !window.location.hash) {
        setTimeout(function () { editingCard.scrollIntoView({ block: "start" }); }, 100);
    }

    // ----- Delete pop-up (reports, comments and replies all use this one) -----
    var confirmModal = document.getElementById("confirm-modal");
    var confirmTitle = document.getElementById("confirmTitle");
    var confirmText = document.getElementById("confirmText");
    var confirmCancel = document.getElementById("confirmCancel");
    var confirmOk = document.getElementById("confirmOk");
    var confirmBackdrop = document.getElementById("confirmBackdrop");
    var pendingForm = null;
    var confirmOpener = null;

    var openConfirm = function (btn) {
        pendingForm = btn.form || btn.closest("form");
        confirmOpener = btn;
        confirmTitle.textContent = btn.getAttribute("data-confirm-title") || "Delete?";
        confirmText.textContent = btn.getAttribute("data-confirm-text") || "This can't be undone.";
        confirmModal.classList.add("is-open");
        document.body.classList.add("modal-open");
        confirmCancel.focus();
    };
    var closeConfirm = function () {
        confirmModal.classList.remove("is-open");
        document.body.classList.remove("modal-open");
        pendingForm = null;
        if (confirmOpener && document.body.contains(confirmOpener)) { confirmOpener.focus(); }
        confirmOpener = null;
    };

    if (confirmModal) {
        confirmCancel.addEventListener("click", closeConfirm);
        confirmBackdrop.addEventListener("click", closeConfirm);
        confirmOk.addEventListener("click", function () {
            var form = pendingForm;
            confirmModal.classList.remove("is-open");
            document.body.classList.remove("modal-open");
            if (form) { form.submit(); }                    // the form carries its own "delete" field, so a plain submit works
        });
    }

    var openForm = function () {
        if (!panel) { return; }
        panel.classList.add("is-open");
        panel.scrollIntoView({ behavior: "smooth", block: "start" });
        var first = panel.querySelector("input:not([type=hidden]), select, textarea");
        if (first) { setTimeout(function () { first.focus({ preventScroll: true }); }, 250); }
    };

    // Cancel: close it and empty everything that was typed
    var closeForm = function () {
        if (!panel || !form) { return; }
        panel.classList.remove("is-open");
        Array.prototype.forEach.call(form.elements, function (el) {
            if (el.type === "hidden" || el.type === "submit" || el.type === "button") { return; }
            if (el.tagName === "SELECT") { el.selectedIndex = 0; } else { el.value = ""; }
        });
        var oldError = panel.querySelector(".notice--err");
        if (oldError) { oldError.parentNode.removeChild(oldError); }
        if (pictureHint) { pictureHint.textContent = pictureHintText; pictureHint.classList.remove("is-error"); }
        updateCounter();
    };

    // if the form starts open (came from the Home button, or the last try had an error), bring it into view
    if (panel && panel.classList.contains("is-open") && !window.location.hash) {
        setTimeout(function () { panel.scrollIntoView({ block: "start" }); }, 100);
    }

    // ----- Photo viewer -----
    var lightbox = document.getElementById("lightbox");
    var lightboxImg = document.getElementById("lightboxImg");
    var lightboxClose = document.getElementById("lightboxClose");
    var lastThumb = null;

    var openLightbox = function (link) {
        lastThumb = link;
        lightboxImg.src = link.getAttribute("data-lightbox");
        lightboxImg.alt = link.getAttribute("data-alt") || "";
        lightbox.classList.add("is-open");
        document.body.classList.add("modal-open");
        lightboxClose.focus();
    };
    var closeLightbox = function () {
        lightbox.classList.remove("is-open");
        document.body.classList.remove("modal-open");
        lightboxImg.removeAttribute("src");
        if (lastThumb) { lastThumb.focus(); }
    };

    lightboxClose.addEventListener("click", closeLightbox);
    lightbox.addEventListener("click", function (e) {
        if (e.target === lightbox) { closeLightbox(); }          // the dark area around the photo
    });

    // ----- One click listener for all the small buttons on the cards -----
    document.addEventListener("click", function (e) {
        var el = e.target;
        if (!el.closest) { return; }

        if (el.closest("[data-open-report]")) { openForm(); return; }
        if (el.closest("[data-close-report]")) { closeForm(); return; }

        var photo = el.closest("[data-lightbox]");
        if (photo) { e.preventDefault(); openLightbox(photo); return; }

        // Delete buttons: ask first (without JS they just submit)
        var deleteBtn = el.closest("[data-confirm-delete]");
        if (deleteBtn && confirmModal) { e.preventDefault(); openConfirm(deleteBtn); return; }

        // Edit button on your own report: swap the details for the edit form
        var editBtn = el.closest("[data-toggle-edit]");
        if (editBtn) {
            var card = document.getElementById("report-" + editBtn.getAttribute("data-toggle-edit"));
            if (card) {
                var editing = card.classList.toggle("is-editing");
                editBtn.setAttribute("aria-expanded", editing ? "true" : "false");
                if (editing) {
                    var firstField = card.querySelector(".edit-report .field");
                    if (firstField) { firstField.focus(); }
                }
            }
            return;
        }

        // Cancel on the edit report form: put everything back as it was
        var cancelReport = el.closest("[data-cancel-edit-report]");
        if (cancelReport) {
            var editCard = cancelReport.closest(".report-card");
            var editForm = cancelReport.closest("form");
            editForm.reset();
            refreshEditForm(editForm);
            var oldErr = editForm.querySelector(".notice--err");
            if (oldErr) { oldErr.parentNode.removeChild(oldErr); }
            editCard.classList.remove("is-editing");
            var toggler = editCard.querySelector("[data-toggle-edit]");
            if (toggler) { toggler.setAttribute("aria-expanded", "false"); toggler.focus(); }
            editCard.scrollIntoView({ block: "nearest" });
            return;
        }

        // Edit button on your own comment / reply: swap the bubble for a text box
        var editCommentBtn = el.closest("[data-toggle-edit-comment]");
        if (editCommentBtn) {
            var commentEl = document.getElementById("c-" + editCommentBtn.getAttribute("data-toggle-edit-comment"));
            if (commentEl) {
                var editingComment = commentEl.classList.toggle("is-editing");
                if (editingComment) {
                    var box2 = commentEl.querySelector(".edit-form textarea");
                    if (box2) { box2.focus(); box2.setSelectionRange(box2.value.length, box2.value.length); }
                }
            }
            return;
        }

        var cancelComment = el.closest("[data-cancel-edit-comment]");
        if (cancelComment) {
            var cancelEl = cancelComment.closest(".comment");
            cancelComment.closest("form").reset();
            cancelEl.classList.remove("is-editing");
            return;
        }

        // Comment button: show / hide the comments of that card
        var commentBtn = el.closest("[data-toggle-comments]");
        if (commentBtn) {
            var box = document.getElementById("comments-" + commentBtn.getAttribute("data-toggle-comments"));
            if (box) {
                var nowOpen = box.classList.toggle("is-open");
                commentBtn.setAttribute("aria-expanded", nowOpen ? "true" : "false");
            }
            return;
        }

        // Reply button: show / hide the reply box under that comment
        var replyBtn = el.closest("[data-toggle-reply]");
        if (replyBtn) {
            var replyForm = document.getElementById("reply-" + replyBtn.getAttribute("data-toggle-reply"));
            if (replyForm) {
                var replyOpen = replyForm.classList.toggle("is-open");
                if (replyOpen) { replyForm.querySelector("textarea").focus(); }
            }
            return;
        }

        // "...read more." / "show less"
        if (el.closest(".read-more")) {
            el.closest(".desc").classList.toggle("is-open");
        }
    });

    // ----- Filter panel: close when clicking elsewhere -----
    var filter = document.querySelector(".filter");
    if (filter) {
        document.addEventListener("click", function (e) {
            if (!filter.contains(e.target)) { filter.removeAttribute("open"); }
        });
    }

    // ----- Esc closes whatever is open; Tab stays inside the photo viewer -----
    document.addEventListener("keydown", function (e) {
        if (confirmModal && confirmModal.classList.contains("is-open")) {
            if (e.key === "Escape") { closeConfirm(); }
            if (e.key === "Tab") {                           // keep Tab inside the pop-up
                if (e.shiftKey && document.activeElement === confirmCancel) {
                    e.preventDefault(); confirmOk.focus();
                } else if (!e.shiftKey && document.activeElement === confirmOk) {
                    e.preventDefault(); confirmCancel.focus();
                }
            }
            return;
        }
        if (e.key === "Escape") {
            if (lightbox.classList.contains("is-open")) { closeLightbox(); }
            if (filter) { filter.removeAttribute("open"); }
        }
        if (e.key === "Tab" && lightbox.classList.contains("is-open")) {
            e.preventDefault();
            lightboxClose.focus();
        }
    });
})();
