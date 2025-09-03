# Symfony/Twig Implementation Guide for Workoflow Dark Theme

## Overview
This guide provides step-by-step instructions for implementing the Figma design system in your Symfony 7.2 application with Twig templates and Stimulus controllers.

## 1. Project Structure

```
src/
├── Twig/
│   ├── Components/
│   │   ├── ButtonComponent.php
│   │   ├── CardComponent.php
│   │   ├── InputComponent.php
│   │   └── ChatbotComponent.php
│   └── Extension/
│       └── UIExtension.php
assets/
├── controllers/
│   ├── chatbot_controller.js
│   ├── theme_controller.js
│   └── integration_controller.js
├── styles/
│   ├── app.scss
│   ├── components/
│   │   ├── _buttons.scss
│   │   ├── _forms.scss
│   │   ├── _cards.scss
│   │   └── _chatbot.scss
│   └── themes/
│       └── _dark.scss
templates/
├── base.html.twig
├── components/
│   ├── button.html.twig
│   ├── card.html.twig
│   ├── form/
│   │   ├── input.html.twig
│   │   ├── select.html.twig
│   │   └── checkbox.html.twig
│   └── chatbot.html.twig
└── pages/
    ├── landing.html.twig
    ├── dashboard.html.twig
    └── settings.html.twig
```

## 2. Base Template Setup

### templates/base.html.twig
```twig
<!DOCTYPE html>
<html lang="{{ app.request.locale }}" class="dark" data-controller="theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}Workoflow - AI Integration Platform{% endblock %}</title>
    
    {# Preload fonts #}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    {# CSS with dark theme variables #}
    {{ encore_entry_link_tags('app') }}
    
    {% block stylesheets %}{% endblock %}
</head>
<body class="bg-background text-text-primary min-h-screen">
    {# Skip to content for accessibility #}
    <a href="#main-content" class="sr-only focus:not-sr-only">Skip to content</a>
    
    {% block body %}{% endblock %}
    
    {# Global Chatbot Component #}
    {% if app.user %}
        {{ component('chatbot', {
            user: app.user,
            organization: app.user.organization
        }) }}
    {% endif %}
    
    {# JavaScript #}
    {{ encore_entry_script_tags('app') }}
    {% block javascripts %}{% endblock %}
</body>
</html>
```

## 3. Twig Components (Symfony UX)

### src/Twig/Components/ButtonComponent.php
```php
<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('button')]
class ButtonComponent
{
    public string $variant = 'primary';
    public string $size = 'default';
    public ?string $href = null;
    public bool $disabled = false;
    public ?string $icon = null;
    public string $type = 'button';
    
    public function getClasses(): string
    {
        $classes = ['inline-flex', 'items-center', 'justify-center', 'font-medium', 'transition-all', 'rounded-md'];
        
        // Size classes
        $sizeClasses = match($this->size) {
            'sm' => ['text-sm', 'px-3', 'py-1.5', 'gap-1.5'],
            'lg' => ['text-base', 'px-6', 'py-3', 'gap-2.5'],
            default => ['text-sm', 'px-4', 'py-2', 'gap-2'],
        };
        
        // Variant classes
        $variantClasses = match($this->variant) {
            'primary' => ['bg-primary', 'text-white', 'hover:bg-primary-hover', 'focus:ring-2', 'focus:ring-primary', 'focus:ring-offset-2', 'focus:ring-offset-background'],
            'secondary' => ['bg-transparent', 'text-text-primary', 'border', 'border-border', 'hover:bg-background-secondary', 'hover:border-border-hover'],
            'destructive' => ['bg-red-600', 'text-white', 'hover:bg-red-700'],
            'ghost' => ['text-text-primary', 'hover:bg-background-secondary'],
            default => [],
        };
        
        // Disabled classes
        if ($this->disabled) {
            $classes[] = 'opacity-50';
            $classes[] = 'cursor-not-allowed';
        }
        
        return implode(' ', array_merge($classes, $sizeClasses, $variantClasses));
    }
}
```

### templates/components/button.html.twig
```twig
{# @var \App\Twig\Components\ButtonComponent $this #}
{% if href %}
    <a href="{{ href }}" class="{{ this.classes }}" 
       {% if disabled %}aria-disabled="true" tabindex="-1"{% endif %}>
        {% if icon %}
            <svg class="w-4 h-4" aria-hidden="true">
                <use href="#icon-{{ icon }}"></use>
            </svg>
        {% endif %}
        {% block content %}{% endblock %}
    </a>
{% else %}
    <button type="{{ type }}" class="{{ this.classes }}" 
            {% if disabled %}disabled{% endif %}>
        {% if icon %}
            <svg class="w-4 h-4" aria-hidden="true">
                <use href="#icon-{{ icon }}"></use>
            </svg>
        {% endif %}
        {% block content %}{% endblock %}
    </button>
{% endif %}
```

## 4. Stimulus Controllers

### assets/controllers/chatbot_controller.js
```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['window', 'trigger', 'messages', 'input', 'sendButton'];
    static values = {
        open: Boolean,
        endpoint: String,
        userId: String,
        organizationId: String
    };
    
    connect() {
        // Initialize chatbot state
        this.openValue = false;
        this.messages = [];
        
        // Set up keyboard shortcuts
        this.setupKeyboardShortcuts();
        
        // Load chat history if available
        this.loadChatHistory();
    }
    
    disconnect() {
        // Clean up event listeners
        document.removeEventListener('keydown', this.handleKeydown);
    }
    
    setupKeyboardShortcuts() {
        this.handleKeydown = (e) => {
            // Cmd/Ctrl + K to toggle chatbot
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                this.toggle();
            }
        };
        document.addEventListener('keydown', this.handleKeydown);
    }
    
    toggle() {
        this.openValue = !this.openValue;
        
        if (this.openValue) {
            this.windowTarget.classList.remove('opacity-0', 'pointer-events-none', 'scale-95');
            this.windowTarget.classList.add('opacity-100', 'scale-100');
            this.inputTarget.focus();
            
            // Animate trigger button
            this.triggerTarget.classList.add('rotate-180');
        } else {
            this.windowTarget.classList.add('opacity-0', 'pointer-events-none', 'scale-95');
            this.windowTarget.classList.remove('opacity-100', 'scale-100');
            this.triggerTarget.classList.remove('rotate-180');
        }
    }
    
    async sendMessage(event) {
        event.preventDefault();
        
        const message = this.inputTarget.value.trim();
        if (!message) return;
        
        // Add user message to chat
        this.addMessage('user', message);
        
        // Clear input
        this.inputTarget.value = '';
        this.inputTarget.focus();
        
        // Show typing indicator
        this.showTypingIndicator();
        
        try {
            // Send message to backend
            const response = await fetch(this.endpointValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCSRFToken()
                },
                body: JSON.stringify({
                    message: message,
                    userId: this.userIdValue,
                    organizationId: this.organizationIdValue,
                    context: this.getCurrentContext()
                })
            });
            
            const data = await response.json();
            
            // Remove typing indicator
            this.hideTypingIndicator();
            
            // Add bot response
            this.addMessage('bot', data.response, data.actions);
            
        } catch (error) {
            this.hideTypingIndicator();
            this.addMessage('bot', 'Sorry, I encountered an error. Please try again.');
            console.error('Chatbot error:', error);
        }
    }
    
    addMessage(sender, text, actions = []) {
        const messageEl = document.createElement('div');
        messageEl.className = `flex ${sender === 'user' ? 'justify-end' : 'justify-start'} mb-4`;
        
        const bubbleEl = document.createElement('div');
        bubbleEl.className = `max-w-[80%] px-4 py-2 rounded-lg ${
            sender === 'user' 
                ? 'bg-primary text-white' 
                : 'bg-background-secondary text-text-primary border border-border'
        }`;
        
        bubbleEl.textContent = text;
        messageEl.appendChild(bubbleEl);
        
        // Add action buttons if provided
        if (actions.length > 0) {
            const actionsEl = document.createElement('div');
            actionsEl.className = 'mt-2 space-x-2';
            
            actions.forEach(action => {
                const btn = document.createElement('button');
                btn.className = 'text-xs px-2 py-1 rounded bg-primary/10 text-primary hover:bg-primary/20';
                btn.textContent = action.label;
                btn.onclick = () => this.handleAction(action);
                actionsEl.appendChild(btn);
            });
            
            bubbleEl.appendChild(actionsEl);
        }
        
        this.messagesTarget.appendChild(messageEl);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
    
    showTypingIndicator() {
        const indicator = document.createElement('div');
        indicator.id = 'typing-indicator';
        indicator.className = 'flex justify-start mb-4';
        indicator.innerHTML = `
            <div class="bg-background-secondary border border-border px-4 py-2 rounded-lg">
                <div class="flex space-x-2">
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                </div>
            </div>
        `;
        this.messagesTarget.appendChild(indicator);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }
    
    hideTypingIndicator() {
        const indicator = document.getElementById('typing-indicator');
        if (indicator) {
            indicator.remove();
        }
    }
    
    getCurrentContext() {
        // Get current page context for better AI responses
        return {
            page: window.location.pathname,
            title: document.title,
            timestamp: new Date().toISOString()
        };
    }
    
    getCSRFToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }
    
    loadChatHistory() {
        // Load from localStorage or backend
        const history = localStorage.getItem('chatbot-history');
        if (history) {
            this.messages = JSON.parse(history);
            this.messages.forEach(msg => {
                this.addMessage(msg.sender, msg.text, msg.actions);
            });
        }
    }
    
    handleAction(action) {
        switch (action.type) {
            case 'navigate':
                window.location.href = action.url;
                break;
            case 'integration':
                this.dispatch('integration-action', { detail: action });
                break;
            case 'help':
                this.addMessage('user', action.label);
                this.sendHelpRequest(action.topic);
                break;
        }
    }
}
```

## 5. SCSS Styles

### assets/styles/themes/_dark.scss
```scss
// Dark theme CSS custom properties
:root.dark {
    // Backgrounds
    --color-background: 10 10 10; // #0a0a0a
    --color-background-secondary: 26 26 26; // #1a1a1a
    --color-background-card: 26 26 26; // #1a1a1a
    
    // Brand Colors
    --color-primary: 255 107 53; // #ff6b35
    --color-primary-hover: 229 90 43; // #e55a2b
    --color-accent-purple: 168 85 247; // #a855f7
    
    // Text Colors
    --color-text-primary: 255 255 255; // #ffffff
    --color-text-secondary: 156 163 175; // #9ca3af
    --color-text-muted: 107 114 128; // #6b7280
    
    // Border Colors
    --color-border: 42 42 42; // #2a2a2a
    --color-border-hover: 58 58 58; // #3a3a3a
    
    // Status Colors
    --color-success: 16 185 129; // #10b981
    --color-warning: 245 158 11; // #f59e0b
    --color-error: 239 68 68; // #ef4444
    --color-info: 59 130 246; // #3b82f6
    
    // Shadows
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

// Utility classes using CSS variables
.bg-background {
    background-color: rgb(var(--color-background));
}

.bg-background-secondary {
    background-color: rgb(var(--color-background-secondary));
}

.bg-primary {
    background-color: rgb(var(--color-primary));
}

.text-primary {
    color: rgb(var(--color-primary));
}

// ... more utility classes
```

### assets/styles/components/_chatbot.scss
```scss
.chatbot {
    position: fixed;
    bottom: 1.25rem;
    right: 1.25rem;
    z-index: 50;
    
    &-trigger {
        @apply w-14 h-14 bg-primary rounded-full flex items-center justify-center;
        @apply shadow-lg hover:shadow-xl transform transition-all duration-200;
        @apply hover:scale-105 active:scale-95;
        
        svg {
            @apply w-6 h-6 text-white;
        }
        
        &.rotate-180 {
            @apply rotate-180;
        }
    }
    
    &-window {
        @apply absolute bottom-16 right-0;
        @apply w-96 h-[600px] max-h-[80vh];
        @apply bg-background-secondary border border-border rounded-xl;
        @apply shadow-2xl transform transition-all duration-300 origin-bottom-right;
        @apply flex flex-col overflow-hidden;
        
        &-header {
            @apply bg-primary px-6 py-4 flex items-center justify-between;
            @apply text-white border-b border-primary-hover;
            
            h3 {
                @apply font-semibold text-lg;
            }
            
            button {
                @apply w-8 h-8 rounded-full flex items-center justify-center;
                @apply hover:bg-white/10 transition-colors;
            }
        }
        
        &-messages {
            @apply flex-1 overflow-y-auto p-4 space-y-4;
            @apply scrollbar-thin scrollbar-thumb-border scrollbar-track-background;
        }
        
        &-input {
            @apply p-4 border-t border-border;
            
            form {
                @apply flex gap-2;
            }
            
            input {
                @apply flex-1 bg-background border border-border rounded-lg px-4 py-2;
                @apply text-text-primary placeholder-text-muted;
                @apply focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20;
            }
            
            button {
                @apply px-4 py-2 bg-primary text-white rounded-lg;
                @apply hover:bg-primary-hover transition-colors;
                @apply disabled:opacity-50 disabled:cursor-not-allowed;
            }
        }
    }
}

// Mobile responsive
@media (max-width: 640px) {
    .chatbot {
        &-window {
            @apply w-full h-full max-h-full;
            @apply bottom-0 right-0 left-0;
            @apply rounded-none;
        }
    }
}
```

## 6. Controller Implementation

### src/Controller/ChatbotController.php
```php
<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ChatbotService;
use App\Service\IntegrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/chatbot', name: 'app_chatbot_')]
class ChatbotController extends AbstractController
{
    public function __construct(
        private ChatbotService $chatbotService,
        private IntegrationService $integrationService,
    ) {}
    
    #[Route('/message', name: 'message', methods: ['POST'])]
    public function message(
        Request $request,
        #[CurrentUser] User $user
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        
        $message = $data['message'] ?? '';
        $context = $data['context'] ?? [];
        
        // Get user's active integrations
        $integrations = $this->integrationService->getActiveIntegrations($user);
        
        // Process message with AI
        $response = $this->chatbotService->processMessage(
            $message,
            $user,
            $integrations,
            $context
        );
        
        return $this->json([
            'response' => $response['text'],
            'actions' => $response['actions'] ?? [],
            'metadata' => [
                'timestamp' => new \DateTime(),
                'integrations_available' => count($integrations),
            ]
        ]);
    }
    
    #[Route('/suggest', name: 'suggest', methods: ['GET'])]
    public function suggest(#[CurrentUser] User $user): JsonResponse
    {
        $suggestions = $this->chatbotService->getSuggestions($user);
        
        return $this->json([
            'suggestions' => $suggestions
        ]);
    }
}
```

## 7. Page Templates

### templates/pages/dashboard.html.twig
```twig
{% extends 'base.html.twig' %}

{% block title %}Dashboard - {{ parent() }}{% endblock %}

{% block body %}
<div class="flex h-screen bg-background">
    {# Sidebar #}
    <aside class="w-64 bg-background-secondary border-r border-border">
        <div class="p-6">
            {# Logo with circular progress #}
            <div class="flex items-center gap-3 mb-8">
                <div class="relative w-12 h-12">
                    <svg class="w-12 h-12 -rotate-90">
                        <circle cx="24" cy="24" r="22" stroke="currentColor" 
                                stroke-width="2" fill="none" class="text-border" />
                        <circle cx="24" cy="24" r="22" stroke="currentColor" 
                                stroke-width="3" fill="none" class="text-primary"
                                stroke-dasharray="138" stroke-dashoffset="30" />
                    </svg>
                    <span class="absolute inset-0 flex items-center justify-center text-white font-bold">
                        V
                    </span>
                </div>
                <span class="text-xl font-semibold text-text-primary">Workoflow</span>
            </div>
            
            {# Navigation #}
            <nav class="space-y-2">
                {{ component('nav_item', {
                    href: path('app_dashboard'),
                    icon: 'home',
                    label: 'Dashboard',
                    active: app.request.get('_route') == 'app_dashboard'
                }) }}
                
                {{ component('nav_item', {
                    href: path('app_integrations'),
                    icon: 'puzzle',
                    label: 'Integrations',
                    active: app.request.get('_route') starts with 'app_integrations'
                }) }}
                
                {{ component('nav_item', {
                    href: path('app_settings'),
                    icon: 'settings',
                    label: 'Settings',
                    active: app.request.get('_route') starts with 'app_settings'
                }) }}
            </nav>
        </div>
        
        {# User menu at bottom #}
        <div class="mt-auto p-6 border-t border-border">
            <div class="flex items-center gap-3">
                {{ component('avatar', {
                    src: app.user.avatarUrl,
                    alt: app.user.displayName,
                    size: 'sm'
                }) }}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-text-primary truncate">
                        {{ app.user.displayName }}
                    </p>
                    <p class="text-xs text-text-secondary truncate">
                        {{ app.user.email }}
                    </p>
                </div>
            </div>
        </div>
    </aside>
    
    {# Main content #}
    <main id="main-content" class="flex-1 overflow-y-auto">
        <div class="p-8">
            {# Page header #}
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-text-primary mb-2">
                    Welcome back, {{ app.user.firstName }}
                </h1>
                <p class="text-text-secondary">
                    Here's what's happening with your integrations today.
                </p>
            </div>
            
            {# Stats cards #}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                {{ component('stat_card', {
                    title: 'Active Integrations',
                    value: stats.activeIntegrations,
                    change: '+2',
                    trend: 'up',
                    icon: 'link'
                }) }}
                
                {{ component('stat_card', {
                    title: 'API Calls Today',
                    value: stats.apiCallsToday|number_format,
                    change: '+12%',
                    trend: 'up',
                    icon: 'activity'
                }) }}
                
                {{ component('stat_card', {
                    title: 'Tasks Synced',
                    value: stats.tasksSynced|number_format,
                    change: '+45',
                    trend: 'up',
                    icon: 'check-circle'
                }) }}
                
                {{ component('stat_card', {
                    title: 'Success Rate',
                    value: stats.successRate ~ '%',
                    change: '+2.3%',
                    trend: 'up',
                    icon: 'trending-up'
                }) }}
            </div>
            
            {# Integration cards #}
            <section>
                <h2 class="text-xl font-semibold text-text-primary mb-4">
                    Your Integrations
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {% for integration in integrations %}
                        {{ component('integration_card', {
                            integration: integration
                        }) }}
                    {% endfor %}
                    
                    {# Add new integration card #}
                    <a href="{{ path('app_integrations_new') }}" 
                       class="group relative bg-background-secondary border-2 border-dashed border-border rounded-lg p-6 hover:border-primary transition-colors">
                        <div class="text-center">
                            <div class="w-12 h-12 mx-auto mb-4 rounded-full bg-primary/10 flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                                <svg class="w-6 h-6 text-primary">
                                    <use href="#icon-plus"></use>
                                </svg>
                            </div>
                            <h3 class="text-lg font-medium text-text-primary mb-1">
                                Add Integration
                            </h3>
                            <p class="text-sm text-text-secondary">
                                Connect a new service
                            </p>
                        </div>
                    </a>
                </div>
            </section>
        </div>
    </main>
</div>
{% endblock %}
```

## 8. Webpack Encore Configuration

### webpack.config.js
```javascript
const Encore = require('@symfony/webpack-encore');

// Runtime environment
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')
    
    // Entry points
    .addEntry('app', './assets/app.js')
    
    // Enable features
    .enableStimulusBridge('./assets/controllers.json')
    .enableSassLoader()
    .enablePostCssLoader()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())
    
    // Configure PostCSS
    .configurePostCssLoader((options) => {
        options.postcssOptions = {
            plugins: [
                require('tailwindcss'),
                require('autoprefixer'),
            ],
        };
    })
    
    // Split chunks
    .splitEntryChunks()
    .enableSingleRuntimeChunk()
    
    // Clean output before build
    .cleanupOutputBeforeBuild()
    
    // Show build notifications
    .enableBuildNotifications()
    
    // Use polling in Docker
    .configureWatchOptions(watchOptions => {
        watchOptions.poll = 250;
    });

module.exports = Encore.getWebpackConfig();
```

## 9. Tailwind Configuration

### tailwind.config.js
```javascript
/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './templates/**/*.html.twig',
        './assets/**/*.js',
        './src/Twig/Components/**/*.php',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                // Map CSS variables to Tailwind
                background: 'rgb(var(--color-background) / <alpha-value>)',
                'background-secondary': 'rgb(var(--color-background-secondary) / <alpha-value>)',
                'background-card': 'rgb(var(--color-background-card) / <alpha-value>)',
                primary: 'rgb(var(--color-primary) / <alpha-value>)',
                'primary-hover': 'rgb(var(--color-primary-hover) / <alpha-value>)',
                'accent-purple': 'rgb(var(--color-accent-purple) / <alpha-value>)',
                'text-primary': 'rgb(var(--color-text-primary) / <alpha-value>)',
                'text-secondary': 'rgb(var(--color-text-secondary) / <alpha-value>)',
                'text-muted': 'rgb(var(--color-text-muted) / <alpha-value>)',
                border: 'rgb(var(--color-border) / <alpha-value>)',
                'border-hover': 'rgb(var(--color-border-hover) / <alpha-value>)',
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },
            animation: {
                'bounce': 'bounce 1s infinite',
                'pulse': 'pulse 2s infinite',
                'spin-slow': 'spin 3s linear infinite',
            },
            keyframes: {
                bounce: {
                    '0%, 100%': { transform: 'translateY(-25%)' },
                    '50%': { transform: 'translateY(0)' },
                },
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
        require('@tailwindcss/typography'),
        require('tailwind-scrollbar'),
    ],
};
```

## 10. Migration Commands

```bash
# Install dependencies
composer require symfony/ux-twig-component
composer require symfony/stimulus-bundle
npm install -D tailwindcss postcss autoprefixer @tailwindcss/forms
npm install @hotwired/stimulus

# Create component classes
php bin/console make:twig-component Button
php bin/console make:twig-component Card
php bin/console make:twig-component Chatbot

# Build assets
npm run build

# Watch for changes during development
npm run watch
```

This implementation guide provides a complete foundation for implementing the Figma design system in your Symfony application with modern frontend practices.