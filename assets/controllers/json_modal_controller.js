import { Controller } from '@hotwired/stimulus';

/**
 * JSON Modal Controller
 * Displays JSON data in a modal with syntax highlighting, copy, collapse, and search features
 */
export default class extends Controller {
    static targets = ['modal', 'content', 'searchInput', 'copyButton', 'title'];

    connect() {
        this.boundHandleEscape = this.handleEscape.bind(this);
        this.originalJson = null;
        this.expandedPaths = new Set();
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleEscape);
    }

    /**
     * Open modal with JSON data
     */
    open(event) {
        const jsonData = event.detail?.data || event.currentTarget.dataset.json;
        const title = event.detail?.title || event.currentTarget.dataset.title || 'JSON Data';

        if (!jsonData) {
            console.error('No JSON data provided');
            return;
        }

        try {
            this.originalJson = typeof jsonData === 'string' ? JSON.parse(jsonData) : jsonData;
            this.titleTarget.textContent = title;
            this.renderJson(this.originalJson);
            this.show();
        } catch (error) {
            console.error('Failed to parse JSON:', error);
            this.contentTarget.innerHTML = '<div class="json-error">Invalid JSON data</div>';
            this.show();
        }
    }

    /**
     * Show the modal
     */
    show() {
        this.modalTarget.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', this.boundHandleEscape);

        // Focus search input for accessibility
        if (this.hasSearchInputTarget) {
            setTimeout(() => this.searchInputTarget.focus(), 100);
        }
    }

    /**
     * Close the modal
     */
    close() {
        this.modalTarget.classList.remove('show');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', this.boundHandleEscape);

        // Clear search
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }

        // Reset expanded paths
        this.expandedPaths.clear();
    }

    /**
     * Handle ESC key press
     */
    handleEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }

    /**
     * Close modal on backdrop click
     */
    closeOnBackdrop(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    /**
     * Copy JSON to clipboard
     */
    async copy() {
        try {
            const jsonString = JSON.stringify(this.originalJson, null, 2);
            await navigator.clipboard.writeText(jsonString);

            // Show success feedback
            const originalText = this.copyButtonTarget.innerHTML;
            this.copyButtonTarget.innerHTML = '<i class="fas fa-check"></i> Copied!';
            this.copyButtonTarget.classList.add('success');

            setTimeout(() => {
                this.copyButtonTarget.innerHTML = originalText;
                this.copyButtonTarget.classList.remove('success');
            }, 2000);
        } catch (error) {
            console.error('Failed to copy:', error);
            this.showToast('error', 'Failed to copy to clipboard');
        }
    }

    /**
     * Search within JSON
     */
    search(event) {
        const query = event.target.value.toLowerCase().trim();

        if (!query) {
            this.renderJson(this.originalJson);
            return;
        }

        // Filter JSON based on search query
        const filtered = this.filterJson(this.originalJson, query);
        this.renderJson(filtered, query);
    }

    /**
     * Filter JSON recursively based on search query
     */
    filterJson(obj, query) {
        if (typeof obj !== 'object' || obj === null) {
            return String(obj).toLowerCase().includes(query) ? obj : null;
        }

        if (Array.isArray(obj)) {
            const filtered = obj
                .map(item => this.filterJson(item, query))
                .filter(item => item !== null);
            return filtered.length > 0 ? filtered : null;
        }

        const filtered = {};
        let hasMatch = false;

        for (const [key, value] of Object.entries(obj)) {
            // Check if key matches
            if (key.toLowerCase().includes(query)) {
                filtered[key] = value;
                hasMatch = true;
                continue;
            }

            // Check if value matches (recursively)
            const filteredValue = this.filterJson(value, query);
            if (filteredValue !== null) {
                filtered[key] = filteredValue;
                hasMatch = true;
            }
        }

        return hasMatch ? filtered : null;
    }

    /**
     * Render JSON with syntax highlighting and collapsible tree
     */
    renderJson(data, highlightQuery = null) {
        this.contentTarget.innerHTML = this.renderValue(data, '', highlightQuery);
    }

    /**
     * Render a JSON value recursively
     */
    renderValue(value, path = '', highlightQuery = null) {
        if (value === null) {
            return '<span class="json-null">null</span>';
        }

        if (typeof value === 'boolean') {
            return `<span class="json-boolean">${value}</span>`;
        }

        if (typeof value === 'number') {
            return `<span class="json-number">${value}</span>`;
        }

        if (typeof value === 'string') {
            const escaped = this.escapeHtml(value);
            const highlighted = highlightQuery
                ? this.highlightText(escaped, highlightQuery)
                : escaped;
            return `<span class="json-string">"${highlighted}"</span>`;
        }

        if (Array.isArray(value)) {
            return this.renderArray(value, path, highlightQuery);
        }

        if (typeof value === 'object') {
            return this.renderObject(value, path, highlightQuery);
        }

        return String(value);
    }

    /**
     * Render an array
     */
    renderArray(arr, path, highlightQuery) {
        if (arr.length === 0) {
            return '<span class="json-bracket">[]</span>';
        }

        const isExpanded = this.expandedPaths.has(path);
        const toggleBtn = `<button class="json-toggle" data-action="click->json-modal#toggleCollapse" data-path="${path}">
            <i class="fas fa-chevron-${isExpanded ? 'down' : 'right'}"></i>
        </button>`;

        let html = `<span class="json-bracket">[</span> ${toggleBtn}`;

        if (!isExpanded) {
            html += `<span class="json-ellipsis"> ... ${arr.length} items</span>`;
        } else {
            html += '<div class="json-block">';
            arr.forEach((item, index) => {
                const itemPath = `${path}[${index}]`;
                html += `<div class="json-line">`;
                html += this.renderValue(item, itemPath, highlightQuery);
                if (index < arr.length - 1) {
                    html += '<span class="json-comma">,</span>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        html += '<span class="json-bracket">]</span>';
        return html;
    }

    /**
     * Render an object
     */
    renderObject(obj, path, highlightQuery) {
        const entries = Object.entries(obj);

        if (entries.length === 0) {
            return '<span class="json-bracket">{}</span>';
        }

        const isExpanded = this.expandedPaths.has(path) || path === '';
        const toggleBtn = path ? `<button class="json-toggle" data-action="click->json-modal#toggleCollapse" data-path="${path}">
            <i class="fas fa-chevron-${isExpanded ? 'down' : 'right'}"></i>
        </button>` : '';

        let html = `<span class="json-bracket">{</span> ${toggleBtn}`;

        if (!isExpanded && path !== '') {
            html += `<span class="json-ellipsis"> ... ${entries.length} keys</span>`;
        } else {
            html += '<div class="json-block">';
            entries.forEach(([key, value], index) => {
                const keyPath = path ? `${path}.${key}` : key;
                const keyHighlighted = highlightQuery
                    ? this.highlightText(this.escapeHtml(key), highlightQuery)
                    : this.escapeHtml(key);

                html += `<div class="json-line">`;
                html += `<span class="json-key">"${keyHighlighted}"</span>`;
                html += '<span class="json-colon">: </span>';
                html += this.renderValue(value, keyPath, highlightQuery);
                if (index < entries.length - 1) {
                    html += '<span class="json-comma">,</span>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        html += '<span class="json-bracket">}</span>';
        return html;
    }

    /**
     * Toggle collapse/expand for a path
     */
    toggleCollapse(event) {
        const path = event.currentTarget.dataset.path;

        if (this.expandedPaths.has(path)) {
            this.expandedPaths.delete(path);
        } else {
            this.expandedPaths.add(path);
        }

        // Re-render with current search query
        const query = this.hasSearchInputTarget ? this.searchInputTarget.value.toLowerCase().trim() : '';
        const data = query ? this.filterJson(this.originalJson, query) : this.originalJson;
        this.renderJson(data, query);
    }

    /**
     * Escape HTML special characters
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Highlight search query in text
     */
    highlightText(text, query) {
        const regex = new RegExp(`(${query})`, 'gi');
        return text.replace(regex, '<mark class="json-highlight">$1</mark>');
    }

    /**
     * Show toast notification
     */
    showToast(type, message) {
        let toast = document.getElementById('toast-notification');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast-notification';
            toast.className = 'toast-notification';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.className = `toast-notification toast-${type} show`;

        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }
}
