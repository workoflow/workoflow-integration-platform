import { Controller } from '@hotwired/stimulus';

/**
 * Audit Log Controller
 * Handles table sorting and JSON data modal interaction
 */
export default class extends Controller {
    static targets = ['sortIcon'];

    /**
     * Sort table by column
     */
    sort(event) {
        event.preventDefault();

        const link = event.currentTarget;
        const column = link.dataset.column;
        const currentSortBy = link.dataset.currentSort || 'createdAt';
        const currentSortDir = link.dataset.currentDir || 'DESC';

        // Determine new sort direction
        let newSortDir = 'DESC';
        if (column === currentSortBy) {
            // Toggle direction if clicking the same column
            newSortDir = currentSortDir === 'DESC' ? 'ASC' : 'DESC';
        }

        // Build URL with new sorting parameters
        const url = new URL(window.location.href);
        url.searchParams.set('sortBy', column);
        url.searchParams.set('sortDir', newSortDir);
        url.searchParams.set('page', '1'); // Reset to first page when sorting changes

        // Navigate to new URL (this will reload the page with new sorting)
        window.location.href = url.toString();
    }

    /**
     * Open JSON modal with data from clicked cell
     */
    openJsonModal(event) {
        event.preventDefault();

        const cell = event.currentTarget;
        const jsonData = cell.dataset.json;
        const title = cell.dataset.title || 'Audit Log Data';

        if (!jsonData) {
            console.warn('No JSON data found in cell');
            return;
        }

        // Dispatch custom event to open the JSON modal
        // The json-modal controller will listen for this event
        const jsonModalController = this.application.getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="json-modal"]'),
            'json-modal'
        );

        if (jsonModalController) {
            jsonModalController.open({
                detail: {
                    data: jsonData,
                    title: title
                }
            });
        } else {
            console.error('JSON modal controller not found');
        }
    }

    /**
     * Handle filter form submission with loading state
     */
    submitFilter(event) {
        const form = event.target;
        const submitButton = form.querySelector('button[type="submit"]');

        if (submitButton) {
            const originalText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...';

            // Form will submit naturally, this just adds visual feedback
        }
    }
}
