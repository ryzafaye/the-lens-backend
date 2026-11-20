/**
 * ============================================================================
 * THE LENS — ACTIVITY LOGS LOGIC (EXECUTIVE PORTAL)
 * Clean, Minimalist, Distraction-Free Implementation
 * 
 * Sentence Format:
 * [Causer Name] [action] the [Subject Type] "[Subject Name]" [timestamp].
 * Under it: Role and time only.
 * ============================================================================
 */

document.addEventListener('DOMContentLoaded', () => {

    // =========================================================================
    // 1. AUTHENTICATION & ROLE VERIFICATION
    // =========================================================================
    const userRole = localStorage.getItem('userRole') || 'President';
    const userName = localStorage.getItem('userName') || 'Juan Dela Cruz';

    if (!localStorage.getItem('userRole')) {
        localStorage.setItem('userRole', 'President');
    }

    // =========================================================================
    // 2. ESCAPE HTML HELPER & STATEMENT GRAMMAR FORMATTER
    // =========================================================================
    function escapeHTML(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatActivityStatement(item) {
        if (!item) return { causer: 'System', beforeSubject: 'performed an action', subjectName: '', afterSubject: '', fullSentence: 'System performed an action' };
        const causer = (item.causer || item.user || 'System').trim();
        let rawType = item.subjectType || item.subject?.type || 'Article';
        let lowerType = String(rawType).trim().toLowerCase();
        if (['record', 'desk record', 'editorial item', ''].includes(lowerType)) {
            lowerType = 'article';
        }
        let typePhrase = `the ${lowerType}`;
        if (['officer account', 'user account', 'user'].includes(lowerType)) {
            typePhrase = 'the officer account';
        } else if (['photo', 'photograph'].includes(lowerType)) {
            typePhrase = 'the photo';
        } else if (['media asset', 'graphic material', 'graphic'].includes(lowerType)) {
            typePhrase = 'the media asset';
        } else if (['editorial cartoon', 'cartoon'].includes(lowerType)) {
            typePhrase = 'the editorial cartoon';
        }

        let rawSubjectName = item.subjectName || item.target || item.title || '';
        let cleanSubjectName = String(rawSubjectName).trim();
        if (['record', 'desk record', 'untitled article', 'editorial item'].includes(cleanSubjectName.toLowerCase())) {
            cleanSubjectName = '';
        }

        let beforeSubject = '';
        let afterSubject = '';

        let hasCleanStatementBefore = false;
        if (item.statementBefore !== undefined && item.statementBefore !== null && item.statementBefore !== '') {
            const rawBefore = String(item.statementBefore).trim();
            const beforeLower = rawBefore.toLowerCase();
            const isMalformed =
                beforeLower.includes('edited manuscript') ||
                beforeLower.includes('returned for revision the') ||
                beforeLower.includes('the article the article') ||
                rawBefore.includes('the Article') ||
                rawBefore.includes('the Editorial Item') ||
                rawBefore.includes('the Desk Record');

            if (!isMalformed) {
                beforeSubject = rawBefore;
                afterSubject = item.statementAfter || '';
                hasCleanStatementBefore = true;
            }
        }

        if (!hasCleanStatementBefore) {
            const actionRaw = String(item.action || '').trim();
            const actionLower = actionRaw.toLowerCase();

            if (actionLower === 'edited manuscript' || actionLower === 'edited article' || actionLower === 'updated manuscript') {
                beforeSubject = `edited the manuscript for ${typePhrase}`;
            } else if (actionLower === 'returned for revision' || actionLower === 'returned article for revision' || actionLower === 'returned for revisions') {
                beforeSubject = `returned ${typePhrase}`;
                afterSubject = 'for revision';
            } else if (actionLower.startsWith('returned') && actionLower.includes('revision')) {
                let noun = typePhrase;
                if (actionLower.includes('cartoon')) noun = 'the editorial cartoon';
                else if (actionLower.includes('photo')) noun = 'the photo';
                else if (actionLower.includes('visual')) noun = 'the visual asset';
                beforeSubject = `returned ${noun}`;
                afterSubject = 'for revision';
            } else if (actionLower.startsWith('endorsed') && (actionLower.includes('eic') || actionLower.includes('editor-in-chief'))) {
                let noun = typePhrase;
                if (actionLower.includes('cartoon')) noun = 'the editorial cartoon';
                else if (actionLower.includes('photo')) noun = 'the photo';
                else if (actionLower.includes('visual')) noun = 'the visual asset';
                beforeSubject = `endorsed ${noun}`;
                afterSubject = 'to the EIC';
            } else if (actionLower === 'assigned cover to') {
                beforeSubject = `assigned a cover to ${typePhrase}`;
            } else if (actionLower === 'created account for' || actionLower === 'created user') {
                beforeSubject = 'created an officer account for';
            } else if (actionLower === 'updated role for') {
                beforeSubject = 'updated the role for';
            } else if (actionLower === 'deactivated account for') {
                beforeSubject = 'deactivated the account for';
            } else if (actionLower === 'activated account for') {
                beforeSubject = 'activated the account for';
            } else if (actionLower === 'approved visual artwork') {
                beforeSubject = 'approved the visual artwork';
            } else if (actionLower === 'submitted editorial cartoon') {
                beforeSubject = 'submitted the editorial cartoon';
            } else if (actionLower === 'submitted photo') {
                beforeSubject = 'submitted the photo';
            } else if (actionLower === 'submitted media' || actionLower === 'submitted illustration') {
                beforeSubject = `submitted ${typePhrase}`;
            } else if (['emergency delete', 'emergency deleted', 'hard delete', 'hard deleted', 'permanently delete', 'permanently deleted'].includes(actionLower)) {
                beforeSubject = `permanently deleted ${typePhrase}`;
            } else if (['deleted', 'deleted article'].includes(actionLower)) {
                beforeSubject = `deleted ${typePhrase}`;
            } else if (['rejected', 'declined', 'rejected article', 'declined article'].includes(actionLower)) {
                beforeSubject = `rejected ${typePhrase}`;
            } else if (['submitted', 'submitted article'].includes(actionLower)) {
                beforeSubject = `submitted ${typePhrase}`;
            } else if (['approved', 'approved article'].includes(actionLower)) {
                beforeSubject = `approved ${typePhrase}`;
            } else if (['published', 'published article'].includes(actionLower)) {
                beforeSubject = `published ${typePhrase}`;
            } else if (['unpublished', 'unpublished article'].includes(actionLower)) {
                beforeSubject = `unpublished ${typePhrase}`;
            } else if (['archived', 'archived article'].includes(actionLower)) {
                beforeSubject = `archived ${typePhrase}`;
            } else if (['restored', 'restored article'].includes(actionLower)) {
                beforeSubject = `restored ${typePhrase}`;
            } else if (['submitted', 'approved', 'published', 'unpublished', 'archived', 'restored'].includes(actionLower)) {
                beforeSubject = `${actionLower} ${typePhrase}`;
            } else {
                beforeSubject = `${actionRaw} ${typePhrase}`;
            }
        }

        const nameQuote = cleanSubjectName ? ` "${cleanSubjectName}"` : '';
        const afterPart = afterSubject ? ` ${afterSubject}` : '';
        const fullSentence = `${causer} ${beforeSubject}${nameQuote}${afterPart}`;

        return {
            causer,
            beforeSubject,
            subjectName: cleanSubjectName,
            afterSubject,
            fullSentence
        };
    }

    // =========================================================================
    // 6. ACTIVITY LOG DATASET
    // =========================================================================
    const ACTIVITY_STORAGE_KEY = 'executiveActivityLogs_v1';

    const defaultActivities = [];

    function loadActivities() {
        if (window.INITIAL_ACTIVITIES && Array.isArray(window.INITIAL_ACTIVITIES) && window.INITIAL_ACTIVITIES.length > 0) {
            return window.INITIAL_ACTIVITIES;
        }
        if (window.INITIAL_DATA && Array.isArray(window.INITIAL_DATA) && window.INITIAL_DATA.length > 0) {
            return window.INITIAL_DATA;
        }
        try {
            const raw = localStorage.getItem(ACTIVITY_STORAGE_KEY);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) return parsed;
            }
        } catch (e) {
            console.warn('Could not read executiveActivityLogs_v1:', e);
        }
        return [];
    }

    let activitiesList = loadActivities();

    // =========================================================================
    // 7. DOM ELEMENTS & CONTROLS
    // =========================================================================
    const searchInput = document.getElementById('activity-search-input');
    const searchClearBtn = document.getElementById('activity-search-clear');
    const dateFilterSelect = document.getElementById('date-filter-select');
    const specificDateWrapper = document.getElementById('specific-date-wrapper');
    const specificDatePicker = document.getElementById('specific-date-picker');
    const timelineStreamContainer = document.getElementById('activity-stream-timeline');
    const resultsCounterEl = document.getElementById('activity-results-counter');

    // =========================================================================
    // 8. FILTERING ENGINE
    // =========================================================================
    function getFilteredActivities() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const dateFilter = dateFilterSelect ? dateFilterSelect.value : 'all';
        const specificDate = specificDatePicker ? specificDatePicker.value : '';

        const now = Date.now();
        const oneDayMs = 24 * 3600 * 1000;
        const sevenDaysMs = 7 * oneDayMs;
        const thirtyDaysMs = 30 * oneDayMs;

        return activitiesList.filter(item => {
            // Date Filter: today, 7days, 30days, specific
            if (dateFilter !== 'all') {
                const itemTime = new Date(item.isoDate).getTime();
                const diff = now - itemTime;

                if (dateFilter === 'today') {
                    if (item.dayGroup !== 'Today' && diff > oneDayMs) return false;
                } else if (dateFilter === '7days') {
                    if (diff > sevenDaysMs) return false;
                } else if (dateFilter === '30days') {
                    if (diff > thirtyDaysMs) return false;
                } else if (dateFilter === 'specific' && specificDate) {
                    if (item.dateKey !== specificDate) return false;
                }
            }

            // Search query matching causer, action, subject, or role
            if (query) {
                const stmt = formatActivityStatement(item);
                const fullSentence = `${stmt.fullSentence} ${item.timestampText} ${item.causerRole}`.toLowerCase();
                return fullSentence.includes(query);
            }

            return true;
        });
    }

    // =========================================================================
    // 9. RENDERING TIMELINE
    // =========================================================================
    function renderActivities() {
        if (!timelineStreamContainer) return;

        const filtered = getFilteredActivities();

        if (resultsCounterEl) {
            resultsCounterEl.innerHTML = `Showing <em>${filtered.length}</em> of ${activitiesList.length} logged events`;
        }

        if (filtered.length === 0) {
            const isSearching = !!(searchInput && searchInput.value.trim()) || (dateFilterSelect && dateFilterSelect.value !== 'all');
            timelineStreamContainer.innerHTML = `
                <div class="activity-empty-state">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <h4 class="empty-state-title">${isSearching ? 'No activity logs found' : 'No activity logs yet'}</h4>
                </div>
            `;
            return;
        }

        // Group filtered activities by dayGroup
        const groups = {};
        filtered.forEach(item => {
            const groupKey = item.dayGroup || 'Earlier';
            if (!groups[groupKey]) groups[groupKey] = [];
            groups[groupKey].push(item);
        });

        let html = '';

        for (const [day, items] of Object.entries(groups)) {
            html += `
                <div class="timeline-day-divider">
                    <span class="timeline-day-pill">${escapeHTML(day)}</span>
                    <span class="timeline-day-line"></span>
                </div>
            `;

            items.forEach(item => {
                const stmt = formatActivityStatement(item);
                const subjectSpan = stmt.subjectName ? ` <span class="subject-name-token" style="font-family: 'Playfair Display', Georgia, serif !important; margin: 0 4px;">"${escapeHTML(stmt.subjectName)}"</span>` : '';
                const afterSpan = stmt.afterSubject ? ` <span${stmt.subjectName ? '' : ' style="margin-left: 4px;"'}>${escapeHTML(stmt.afterSubject)}</span>` : '';

                html += `
                    <div class="activity-event-card">
                        <div class="activity-event-node"></div>

                        <div class="activity-card-inner">
                            <div class="activity-avatar-wrap">
                                <img src="${escapeHTML(item.causerAvatar || 'images/member-placeholder.svg')}" alt="${escapeHTML(item.causer)}" class="activity-avatar-img">
                            </div>

                            <div class="activity-content-col">
                                <!-- Plain, same font color style sentence -->
                                <p class="activity-sentence-statement">
                                    <strong class="causer-name-token">${escapeHTML(stmt.causer)}</strong> <span>${escapeHTML(stmt.beforeSubject)}</span>${subjectSpan}${afterSpan} <span class="timestamp-token" style="margin-left: 4px;">${escapeHTML(item.timestampText)}.</span>
                                </p>

                                <!-- Under it: Role and time only, no IDs -->
                                <div class="activity-subrow">
                                    <span class="activity-role-text">${escapeHTML(item.causerRole)}</span>
                                    <span class="activity-subrow-dot">•</span>
                                    <span class="activity-exact-time">${escapeHTML(item.formattedDate)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
        }

        timelineStreamContainer.innerHTML = html;
    }

    // =========================================================================
    // 10. EVENT LISTENERS
    // =========================================================================
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            if (searchClearBtn) {
                searchClearBtn.style.display = searchInput.value.length > 0 ? 'block' : 'none';
            }
            renderActivities();
        });
    }

    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', () => {
            if (searchInput) {
                searchInput.value = '';
                searchClearBtn.style.display = 'none';
                searchInput.focus();
                renderActivities();
            }
        });
    }

    if (dateFilterSelect) {
        dateFilterSelect.addEventListener('change', () => {
            const isSpecific = (dateFilterSelect.value === 'specific');
            if (specificDateWrapper) {
                specificDateWrapper.classList.toggle('hidden', !isSpecific);
                if (isSpecific && specificDatePicker) {
                    specificDatePicker.focus();
                }
            }
            renderActivities();
        });
    }

    if (specificDatePicker) {
        specificDatePicker.addEventListener('change', renderActivities);
    }

    // Initial render
    renderActivities();
});
