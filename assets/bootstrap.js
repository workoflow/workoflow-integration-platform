import { Application } from '@hotwired/stimulus';

// Create the Stimulus application
const application = Application.start();

// Register the channel controller
import channelController from './controllers/channel_controller';
application.register('channel', channelController);

// Register the flash message controller
import flashMessageController from './controllers/flash_message_controller';
application.register('flash-message', flashMessageController);

// Register the skills table controller
import skillsTableController from './controllers/skills_table_controller';
application.register('skills-table', skillsTableController);

// Register the integration dropdown controller
import integrationDropdownController from './controllers/integration_dropdown_controller';
application.register('integration-dropdown', integrationDropdownController);

// Export for debugging
window.Stimulus = application;

export { application };