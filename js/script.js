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
//    The Home page has three carousels (reports, lost items, claimed list): each one works on its own.
Array.prototype.forEach.call(document.querySelectorAll("[data-carousel]"), function (carousel) {
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
    // Every few seconds, ask dashboard.php for the 5 newest cards of this carousel. If something changed
    // (a new one, an edit, a deleted one) the cards are swapped and the newest one is back on the first card.
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
});

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

// 9) Report page: report form, comments, replies, "read more", photo viewer, filter panel.
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

// 10) Students page (admin): add/edit form, dynamic year level + strand lists, filter panel.
(function () {
    if (!document.querySelector("[data-student-page]")) { return; }

    var panel = document.getElementById("student-form");
    var form = panel ? panel.querySelector("form") : null;
    var idField = form ? form.querySelector('input[name="student_id"]') : null;
    var eduSel = document.getElementById("education_level");
    var yearSel = document.getElementById("year_level");
    var strandSel = document.getElementById("strand_program");

    var config = {};
    try { config = JSON.parse(panel.getAttribute("data-config") || "{}"); } catch (err) { config = {}; }

    // Replaces the options of a drop-down, keeping the old choice when it is still in the new list
    var setOptions = function (select, items, placeholder, keep) {
        select.innerHTML = "";
        if (placeholder) {
            var first = new Option(placeholder, "", false, false);
            first.disabled = true;
            select.add(first);
        }
        items.forEach(function (value) { select.add(new Option(value, value)); });
        if (keep && items.indexOf(keep) !== -1) { select.value = keep; } else { select.selectedIndex = 0; }
    };

    // Year level + Strand/Program depend on the Education level
    var syncLists = function () {
        var info = config[eduSel.value];
        var keepYear = yearSel.value;
        var keepStrand = strandSel.value;

        if (info) { setOptions(yearSel, info.years, "Choose a year level", keepYear); }
        else { setOptions(yearSel, [], "Choose an education level first", ""); }

        if (info && info.strands.length > 0) {
            strandSel.disabled = false;
            setOptions(strandSel, info.strands, "Choose a strand / program", keepStrand);
        } else {
            strandSel.disabled = true;                 // Elementary / Junior High: stays N/A
            setOptions(strandSel, ["N/A"], "", "N/A");
        }
    };

    if (panel && form && eduSel && yearSel && strandSel) {
        eduSel.addEventListener("change", syncLists);
        syncLists();
    }

    var openForm = function () {
        panel.classList.add("is-open");
        panel.scrollIntoView({ behavior: "smooth", block: "start" });
        var firstField = panel.querySelector("input:not([type=hidden])");
        if (firstField) { setTimeout(function () { firstField.focus({ preventScroll: true }); }, 250); }
    };

    // Cancel (add mode): close the form and empty everything
    var closeForm = function () {
        panel.classList.remove("is-open");
        Array.prototype.forEach.call(form.elements, function (el) {
            if (el.type === "hidden" || el.type === "submit" || el.type === "button") { return; }
            if (el.tagName === "SELECT") { el.selectedIndex = 0; } else { el.value = ""; }
        });
        var oldError = panel.querySelector(".notice--err");
        if (oldError) { oldError.parentNode.removeChild(oldError); }
        syncLists();
    };

    // form already open when the page loads (Edit button, a mistake to fix, or ?new=1): bring it into view
    if (panel && panel.classList.contains("is-open")) {
        setTimeout(function () { panel.scrollIntoView({ block: "start" }); }, 100);
    }

    document.addEventListener("click", function (e) {
        var el = e.target;
        if (!el.closest || !panel) { return; }

        var addBtn = el.closest("[data-open-student]");
        if (addBtn) {
            // while editing, the link reloads the page in "add" mode; otherwise just open the form here
            if (idField && idField.value === "0") { e.preventDefault(); openForm(); }
            return;
        }
        if (el.closest("[data-close-student]")) { e.preventDefault(); closeForm(); }
    });

    // Filter panel closes when you click elsewhere
    var filter = document.querySelector(".filter");
    if (filter) {
        document.addEventListener("click", function (e) {
            if (!filter.contains(e.target)) { filter.removeAttribute("open"); }
        });
    }

    // Esc: close the filter panel, or the delete pop-up
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") { return; }
        if (filter) { filter.removeAttribute("open"); }
        var confirmBox = document.querySelector(".modal.is-open[data-close-url]");
        if (confirmBox) { window.location.href = confirmBox.getAttribute("data-close-url"); }
    });
})();

// 11) Edit + delete on the report lists and the item lists:
//     "Edit" opens the form inside the card, "Edit" on a comment opens its small form,
//     and every delete button asks "Are you sure?" first.
//     Without JavaScript the Edit forms stay visible and the delete buttons simply submit.
(function () {
    document.documentElement.classList.add("js");

    // ----- "123 / 500" counters under the description in the edit forms -----
    var updateEditCounter = function (area) {
        var counter = document.getElementById(area.getAttribute("data-counter-for"));
        if (!counter) { return; }
        var max = parseInt(counter.getAttribute("data-max"), 10);
        counter.textContent = area.value.length + " / " + max;
        counter.classList.toggle("is-near", area.value.length >= max - 40);
    };
    Array.prototype.forEach.call(document.querySelectorAll(".edit-report [data-counter-for]"), function (area) {
        area.addEventListener("input", function () { updateEditCounter(area); });
    });

    // ----- photo too big? say so right away (the server checks again anyway) -----
    Array.prototype.forEach.call(document.querySelectorAll(".edit-report input[type=file][data-max-bytes]"), function (input) {
        var hint = input.parentNode.querySelector(".hint-sm");
        var hintText = hint ? hint.textContent : "";
        input.addEventListener("change", function () {
            var max = parseInt(input.getAttribute("data-max-bytes"), 10);
            var chosen = input.files && input.files[0];
            if (chosen && chosen.size > max) {
                if (hint) { hint.textContent = "That photo is larger than 5 MB. Please choose a smaller one."; hint.classList.add("is-error"); }
                input.value = "";
            } else if (hint) {
                hint.textContent = hintText;
                hint.classList.remove("is-error");
            }
        });
    });

    // ----- Delete confirmation pop-up -----
    var modal = document.getElementById("confirm-modal");
    var title = document.getElementById("confirmTitle");
    var text = document.getElementById("confirmText");
    var cancel = document.getElementById("confirmCancel");
    var ok = document.getElementById("confirmOk");
    var backdrop = document.getElementById("confirmBackdrop");
    var pendingForm = null;

    var closeModal = function () {
        if (!modal) { return; }
        modal.classList.remove("is-open");
        document.body.classList.remove("modal-open");
        pendingForm = null;
    };

    // ----- One click listener for Edit / Cancel / Delete -----
    document.addEventListener("click", function (e) {
        var el = e.target;
        if (!el.closest) { return; }

        // Delete (report, comment, reply, item): ask first
        var del = el.closest("[data-confirm-delete]");
        if (del && del.form && modal) {
            e.preventDefault();
            pendingForm = del.form;
            title.textContent = del.getAttribute("data-confirm-title") || "Delete?";
            text.textContent = del.getAttribute("data-confirm-text") || "This can't be undone.";
            modal.classList.add("is-open");
            document.body.classList.add("modal-open");
            cancel.focus();
            return;
        }

        // Edit a report / item: the card swaps to the form
        var editBtn = el.closest("[data-toggle-edit]");
        if (editBtn) {
            var card = editBtn.closest(".report-card");
            if (card) {
                var nowEditing = card.classList.toggle("is-editing");
                editBtn.setAttribute("aria-expanded", nowEditing ? "true" : "false");
                if (nowEditing) {
                    var first = card.querySelector(".edit-report input:not([type=hidden]), .edit-report textarea");
                    if (first) { first.focus({ preventScroll: true }); }
                    card.scrollIntoView({ behavior: "smooth", block: "nearest" });
                }
            }
            return;
        }

        // Cancel editing a report / item: close it and put back what was there
        var cancelEdit = el.closest("[data-cancel-edit-report]");
        if (cancelEdit) {
            var editCard = cancelEdit.closest(".report-card");
            if (editCard) {
                editCard.classList.remove("is-editing");
                var toggle = editCard.querySelector("[data-toggle-edit]");
                if (toggle) { toggle.setAttribute("aria-expanded", "false"); }

                var editForm = cancelEdit.closest("form");
                if (editForm) {
                    editForm.reset();
                    var oldError = editForm.querySelector(".notice--err");
                    if (oldError) { oldError.parentNode.removeChild(oldError); }
                    Array.prototype.forEach.call(editForm.querySelectorAll("[data-counter-for]"), updateEditCounter);
                    Array.prototype.forEach.call(editForm.querySelectorAll(".hint-sm.is-error"), function (h) { h.classList.remove("is-error"); });
                }
                editCard.scrollIntoView({ behavior: "smooth", block: "nearest" });
            }
            return;
        }

        // Edit a comment / reply
        var editComment = el.closest("[data-toggle-edit-comment]");
        if (editComment) {
            var box = document.getElementById("c-" + editComment.getAttribute("data-toggle-edit-comment"));
            if (box) {
                box.classList.add("is-editing");
                var area = document.getElementById("edit-" + editComment.getAttribute("data-toggle-edit-comment"));
                var field = area ? area.querySelector("textarea") : null;
                if (field) {
                    field.focus();
                    field.setSelectionRange(field.value.length, field.value.length);
                }
            }
            return;
        }

        // Cancel editing a comment / reply
        var cancelComment = el.closest("[data-cancel-edit-comment]");
        if (cancelComment) {
            var commentBox = cancelComment.closest(".comment");
            var commentForm = cancelComment.closest("form");
            if (commentBox) { commentBox.classList.remove("is-editing"); }
            if (commentForm) { commentForm.reset(); }
        }
    });

    if (modal) {
        ok.addEventListener("click", function () {
            var form = pendingForm;
            closeModal();
            if (form) { form.submit(); }          // the hidden btnDelete... field travels with it
        });
        cancel.addEventListener("click", closeModal);
        backdrop.addEventListener("click", closeModal);
    }

    document.addEventListener("keydown", function (e) {
        if (modal && modal.classList.contains("is-open")) {
            if (e.key === "Escape") { closeModal(); }
            if (e.key === "Tab") {                // keep Tab inside the pop-up
                if (e.shiftKey && document.activeElement === cancel) { e.preventDefault(); ok.focus(); }
                else if (!e.shiftKey && document.activeElement === ok) { e.preventDefault(); cancel.focus(); }
            }
            return;
        }
    });

    // A card that opens already in edit mode (the last save had an error): bring it into view
    var openCard = document.querySelector(".report-card.is-editing");
    if (openCard && !window.location.hash) {
        setTimeout(function () { openCard.scrollIntoView({ block: "center" }); }, 100);
    }
})();
