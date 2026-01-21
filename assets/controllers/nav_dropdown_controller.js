import { Controller } from '@hotwired/stimulus';

/**
 * Navigation Dropdown Controller
 * Handles header navigation dropdown menus with:
 * - Click toggle functionality
 * - Click outside to close
 * - ESC key to close
 * - Arrow key navigation within dropdown
 * - Closes other nav dropdowns when one opens
 */
export default class extends Controller {
    static targets = ['button', 'menu'];

    connect() {
        // Bind event handlers
        this.boundHandleClickOutside = this.handleClickOutside.bind(this);
        this.boundHandleEscape = this.handleEscape.bind(this);
        this.boundHandleArrowKeys = this.handleArrowKeys.bind(this);

        document.addEventListener('click', this.boundHandleClickOutside);
        document.addEventListener('keydown', this.boundHandleEscape);
        document.addEventListener('keydown', this.boundHandleArrowKeys);

        // Initialize ARIA state
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.buttonTarget.setAttribute('aria-haspopup', 'true');

        // Mark active menu item based on current URL
        this.highlightActiveItem();
    }

    disconnect() {
        document.removeEventListener('click', this.boundHandleClickOutside);
        document.removeEventListener('keydown', this.boundHandleEscape);
        document.removeEventListener('keydown', this.boundHandleArrowKeys);
    }

    /**
     * Toggle dropdown menu visibility
     */
    toggle(event) {
        event.stopPropagation();

        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Check if dropdown is currently open
     */
    isOpen() {
        return this.menuTarget.style.display === 'block';
    }

    /**
     * Open dropdown menu
     */
    open() {
        // Close all other nav dropdowns first
        this.closeOtherDropdowns();

        this.menuTarget.style.display = 'block';
        this.buttonTarget.classList.add('active');
        this.buttonTarget.setAttribute('aria-expanded', 'true');

        // Focus first menu item for keyboard navigation
        const firstLink = this.menuTarget.querySelector('a, button');
        if (firstLink) {
            setTimeout(() => firstLink.focus(), 0);
        }
    }

    /**
     * Close dropdown menu
     */
    close() {
        this.menuTarget.style.display = 'none';
        this.buttonTarget.classList.remove('active');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    /**
     * Close all other nav-dropdown instances
     */
    closeOtherDropdowns() {
        document.querySelectorAll('[data-controller="nav-dropdown"]').forEach(dropdown => {
            if (dropdown !== this.element) {
                const controller = this.application.getControllerForElementAndIdentifier(dropdown, 'nav-dropdown');
                if (controller && controller.isOpen()) {
                    controller.close();
                }
            }
        });

        // Also close language and organisation dropdowns (legacy inline JS)
        const langDropdown = document.getElementById('langDropdown');
        const orgDropdown = document.getElementById('orgDropdown');
        if (langDropdown) langDropdown.style.display = 'none';
        if (orgDropdown) orgDropdown.style.display = 'none';
    }

    /**
     * Handle clicks outside the dropdown
     */
    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    /**
     * Handle ESC key to close dropdown
     */
    handleEscape(event) {
        if (event.key === 'Escape' && this.isOpen()) {
            this.close();
            this.buttonTarget.focus();
        }
    }

    /**
     * Handle arrow key navigation within dropdown
     */
    handleArrowKeys(event) {
        if (!this.isOpen()) return;

        const items = this.menuTarget.querySelectorAll('a, button:not([disabled])');
        if (items.length === 0) return;

        const currentIndex = Array.from(items).indexOf(document.activeElement);

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
            items[nextIndex].focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
            items[prevIndex].focus();
        }
    }

    /**
     * Highlight active menu item based on current URL path
     */
    highlightActiveItem() {
        const currentPath = window.location.pathname;
        const items = this.menuTarget.querySelectorAll('a');

        items.forEach(item => {
            const href = item.getAttribute('href');
            if (href && currentPath.startsWith(href) && href !== '/') {
                item.classList.add('active');
            } else if (href === '/' && currentPath === '/') {
                item.classList.add('active');
            }
        });

        // If any item is active, add indicator to trigger button
        const hasActiveItem = this.menuTarget.querySelector('a.active');
        if (hasActiveItem) {
            this.buttonTarget.classList.add('has-active');
        }
    }
}
