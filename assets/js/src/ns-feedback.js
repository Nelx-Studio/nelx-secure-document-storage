(function ($) {
    'use strict';

    class NelxSDSTFormFeedback {
        constructor() {
            this.isProcessing = false;
            this.dotInterval = null;
            this.modalEnabled = false;
            this.init();
        }

        async init() {
            await this.checkModalSetting();

            if (!this.modalEnabled) {
                return;
            }

            $(document).on('jet-form-builder/submit/start', this.handleFormSubmit.bind(this));
            $(document).on('jet-form-builder/submit/end', this.handleFormComplete.bind(this));
            $(document).on('jet-form-builder/submit/response', this.handleFormResponse.bind(this));
            $(document).on('jet-form-builder/ajax/on-success', this.handleAjaxSuccess.bind(this));
            $(document).on('jet-form-builder/ajax/on-error', this.handleAjaxError.bind(this));

            this.setupFallbackHandlers();
        }

        async checkModalSetting() {
            try {
                if (typeof nelxsdst_feedback !== 'undefined' && typeof nelxsdst_feedback.modal_enabled !== 'undefined') {
                    this.modalEnabled = nelxsdst_feedback.modal_enabled;
                    return;
                }

                const response = await $.ajax({
                    url: nelxsdst_feedback?.ajax_url || window.ajaxurl ||
                        (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
                    method: 'POST',
                    data: {
                        action: 'nelxsdst_check_form_modal_setting'
                    }
                });

                if (response.success) {
                    this.modalEnabled = response.data.enabled;
                }
            } catch (error) {
                this.modalEnabled = false;
            }
        }

        handleFormSubmit() {
            if (this.modalEnabled) {
                this.showProcessing();
            }
        }

        handleFormComplete() {
            if (this.modalEnabled) {
                this.waitForFeedbackAndClose();
            }
        }

        handleFormResponse() {
            if (this.modalEnabled) {
                this.waitForFeedbackAndClose();
            }
        }

        handleAjaxSuccess() {
            if (this.modalEnabled) {
                this.waitForFeedbackAndClose();
            }
        }

        handleAjaxError() {
            if (this.modalEnabled) {
                this.waitForFeedbackAndClose();
            }
        }

        setupFallbackHandlers() {
            $(document).on('submit', 'form.jet-form-builder', (event) => {
                if (!this.modalEnabled) {
                    return;
                }

                const form = $(event.target);

                if (form.hasClass('jet-form-builder')) {
                    setTimeout(() => {
                        if (!this.isProcessing) {
                            this.showProcessing();
                        }
                    }, 100);
                }
            });

            $(document).ajaxComplete((event, xhr, settings) => {
                if (!this.modalEnabled || !settings.url) {
                    return;
                }

                if (!settings.url.includes('jet-form-builder') && !settings.url.includes('admin-ajax.php')) {
                    return;
                }

                try {
                    const response = xhr.responseJSON || JSON.parse(xhr.responseText || '{}');

                    if (
                        typeof response.success !== 'undefined' ||
                        response.data?.messages ||
                        xhr.getResponseHeader('X-JetFormBuilder')
                    ) {
                        this.hideProcessing();
                    }
                } catch (error) {
                    this.hideProcessing();
                }
            });
        }

        waitForFeedbackAndClose() {
            if (!this.modalEnabled) {
                return;
            }

            const selectors = [
                '.jet-form-builder-message',
                '.jet-form-builder__response',
                '.jet-form-builder-notification',
                '.jet-form-builder-row--response'
            ];

            const findFeedback = () => {
                for (const selector of selectors) {
                    const element = document.querySelector(selector);

                    if (element && element.offsetParent !== null) {
                        return element;
                    }
                }

                return null;
            };

            const feedback = findFeedback();

            if (feedback) {
                this.closeAndScroll(feedback);
                return;
            }

            const observer = new MutationObserver(() => {
                const element = findFeedback();

                if (element) {
                    observer.disconnect();
                    this.closeAndScroll(element);
                }
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            setTimeout(() => {
                observer.disconnect();
                this.hideProcessing();
            }, 8000);
        }

        closeAndScroll(element) {
            if (!this.modalEnabled) {
                return;
            }

            this.hideProcessing();

            setTimeout(() => {
                let scrollContainer = element.parentElement;

                while (scrollContainer) {
                    const overflowY = window.getComputedStyle(scrollContainer).overflowY;

                    if (overflowY === 'auto' || overflowY === 'scroll') {
                        break;
                    }

                    scrollContainer = scrollContainer.parentElement;
                }

                const container = scrollContainer || document.scrollingElement || document.body;
                const rect = element.getBoundingClientRect();

                if (container === document.scrollingElement || container === document.body) {
                    const top = rect.top + window.pageYOffset - 24;

                    window.scrollTo({
                        top: top,
                        behavior: 'smooth'
                    });
                    return;
                }

                const containerRect = container.getBoundingClientRect();

                container.scrollTo({
                    top: container.scrollTop + (rect.top - containerRect.top) - 24,
                    behavior: 'smooth'
                });
            }, 350);
        }

        showProcessing() {
            if (!this.modalEnabled || this.isProcessing) {
                return;
            }

            $('.nelxsdst-form-modal').remove();

            const modal = $(
                '<div class="nelxsdst-form-modal" role="dialog" aria-modal="true" aria-label="Processing">' +
                    '<div class="nelxsdst-modal-overlay"></div>' +
                    '<div class="nelxsdst-modal-content">' +
                        '<div class="nelxsdst-processing-icon" aria-hidden="true">⏳</div>' +
                        '<div class="nelxsdst-processing-text">' +
                            'Processing. Please wait<span class="nelxsdst-processing-dots"></span>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );

            $('body').append(modal);
            modal.hide().fadeIn(300);
            this.animateDots(modal.find('.nelxsdst-processing-dots'));
            this.isProcessing = true;
        }

        hideProcessing() {
            if (!this.modalEnabled) {
                return;
            }

            const modal = $('.nelxsdst-form-modal');

            if (modal.length) {
                if (this.dotInterval) {
                    clearInterval(this.dotInterval);
                    this.dotInterval = null;
                }

                modal.fadeOut(300, function () {
                    $(this).remove();
                });
            }

            this.isProcessing = false;
        }

        animateDots(element) {
            let count = 0;

            const updateDots = () => {
                if (!element.closest('.nelxsdst-form-modal').length) {
                    return;
                }

                count = (count + 1) % 4;
                element.text('.'.repeat(count));
            };

            this.dotInterval = setInterval(updateDots, 500);
        }

        destroy() {
            this.hideProcessing();

            $(document).off('jet-form-builder/submit/start', this.handleFormSubmit);
            $(document).off('jet-form-builder/submit/end', this.handleFormComplete);
            $(document).off('jet-form-builder/submit/response', this.handleFormResponse);
            $(document).off('jet-form-builder/ajax/on-success', this.handleAjaxSuccess);
            $(document).off('jet-form-builder/ajax/on-error', this.handleAjaxError);
            $(document).off('submit', 'form.jet-form-builder');
        }
    }

    $(document).ready(() => {
        setTimeout(() => {
            window.nelxsdstFormFeedback = new NelxSDSTFormFeedback();
        }, 100);
    });

    $(window).on('elementor/popup/show', () => {
        setTimeout(() => {
            if (window.nelxsdstFormFeedback) {
                window.nelxsdstFormFeedback.destroy();
            }

            window.nelxsdstFormFeedback = new NelxSDSTFormFeedback();
        }, 300);
    });

    $(document).on('jet-engine/ajax/loaded', () => {
        setTimeout(() => {
            if (window.nelxsdstFormFeedback) {
                window.nelxsdstFormFeedback.destroy();
            }

            window.nelxsdstFormFeedback = new NelxSDSTFormFeedback();
        }, 300);
    });
}(jQuery));
