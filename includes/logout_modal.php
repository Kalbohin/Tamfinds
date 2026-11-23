<!-- Log out confirmation pop-up (plain HTML + CSS, no <dialog>, so it works on every browser) -->
<div class="modal" id="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
    <a class="modal-backdrop" href="#" id="logoutBackdrop" aria-label="Close"></a>

    <div class="modal-box">
        <div class="modal-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </div>
        <h2 class="modal-title" id="logoutTitle">Log out?</h2>
        <p class="modal-text">Do you want to log out of TamFinds?</p>

        <div class="modal-actions">
            <a class="btn btn--outline" href="#" id="logoutCancel">Cancel</a>
            <a class="btn" href="logout" id="logoutConfirm">Log out</a>
        </div>
    </div>
</div>
