jQuery(document).ready(function($) {
    
    // Apply document mask on billing_document field
    function applyDocumentMask() {
        const documentField = $('#billing_document');
        
        if (documentField.length === 0) {
            return;
        }

        // Remove any existing event handlers to prevent duplicates
        documentField.off('input.documentMask keyup.documentMask');
        
        // Add input event handler
        documentField.on('input.documentMask keyup.documentMask', function() {
            const value = this.value.replace(/\D/g, '');
            
            if (value.length <= 11) {
                // CPF format: 999.999.999-99
                this.value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/g, '$1.$2.$3-$4')
                    .replace(/(\d{3})(\d{3})(\d{3})(\d{1})/g, '$1.$2.$3-$4')
                    .replace(/(\d{3})(\d{3})(\d{2})/g, '$1.$2.$3')
                    .replace(/(\d{3})(\d{2})/g, '$1.$2')
                    .replace(/(\d{3})/g, '$1');
            } else {
                // CNPJ format: 99.999.999/9999-99
                this.value = value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/g, '$1.$2.$3/$4-$5')
                    .replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{1})/g, '$1.$2.$3/$4-$5')
                    .replace(/(\d{2})(\d{3})(\d{3})(\d{4})/g, '$1.$2.$3/$4')
                    .replace(/(\d{2})(\d{3})(\d{3})(\d{3})/g, '$1.$2.$3/$4')
                    .replace(/(\d{2})(\d{3})(\d{3})/g, '$1.$2.$3')
                    .replace(/(\d{2})(\d{3})/g, '$1.$2')
                    .replace(/(\d{2})/g, '$1');
            }
        });

        // Add placeholder update based on field focus
        documentField.on('focus.documentMask', function() {
            if (!this.value) {
                $(this).attr('placeholder', '000.000.000-00 ou 00.000.000/0000-00');
            }
        });

        documentField.on('blur.documentMask', function() {
            if (!this.value) {
                $(this).attr('placeholder', 'Digite seu CPF ou CNPJ');
            }
        });
    }

    // Real-time validation feedback
    function addValidationFeedback() {
        const documentField = $('#billing_document');
        
        if (documentField.length === 0) {
            return;
        }

        // Remove existing feedback elements
        documentField.siblings('.superfrete-field-feedback').remove();
        
        // Add feedback element
        const feedbackElement = $('<div class="superfrete-field-feedback" style="font-size: 12px; margin-top: 5px; display: none;"></div>');
        documentField.after(feedbackElement);

        documentField.on('input.validation blur.validation', function() {
            const value = this.value.replace(/\D/g, '');
            const feedback = feedbackElement;
            
            if (value.length === 0) {
                feedback.hide();
                return;
            }

            if (value.length === 11) {
                if (isValidCPF(value)) {
                    feedback.html('<span style="color: #4CAF50;">✓ CPF válido</span>').show();
                    documentField.removeClass('error');
                } else {
                    feedback.html('<span style="color: #e74c3c;">✗ CPF inválido</span>').show();
                    documentField.addClass('error');
                }
            } else if (value.length === 14) {
                if (isValidCNPJ(value)) {
                    feedback.html('<span style="color: #4CAF50;">✓ CNPJ válido</span>').show();
                    documentField.removeClass('error');
                } else {
                    feedback.html('<span style="color: #e74c3c;">✗ CNPJ inválido</span>').show();
                    documentField.addClass('error');
                }
            } else if (value.length > 0 && value.length < 11) {
                feedback.html('<span style="color: #ff9800;">Ainda digitando CPF...</span>').show();
                documentField.removeClass('error');
            } else if (value.length > 11 && value.length < 14) {
                feedback.html('<span style="color: #ff9800;">Ainda digitando CNPJ...</span>').show();
                documentField.removeClass('error');
            } else if (value.length > 14) {
                feedback.html('<span style="color: #e74c3c;">✗ Documento muito longo</span>').show();
                documentField.addClass('error');
            }
        });
    }

    // CPF validation function
    function isValidCPF(cpf) {
        if (cpf.length !== 11) return false;
        
        // Check for known invalid CPFs
        if (/^(\d)\1{10}$/.test(cpf)) return false;
        
        // Calculate first digit
        let sum = 0;
        for (let i = 0; i < 9; i++) {
            sum += parseInt(cpf[i]) * (10 - i);
        }
        let remainder = sum % 11;
        let digit1 = remainder < 2 ? 0 : 11 - remainder;
        
        if (digit1 !== parseInt(cpf[9])) return false;
        
        // Calculate second digit
        sum = 0;
        for (let i = 0; i < 10; i++) {
            sum += parseInt(cpf[i]) * (11 - i);
        }
        remainder = sum % 11;
        let digit2 = remainder < 2 ? 0 : 11 - remainder;
        
        return digit2 === parseInt(cpf[10]);
    }

    // CNPJ validation function  
    function isValidCNPJ(cnpj) {
        if (cnpj.length !== 14) return false;
        
        // Check for known invalid CNPJs
        if (/^(\d)\1{13}$/.test(cnpj)) return false;
        
        // Calculate first digit
        const weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        let sum = 0;
        for (let i = 0; i < 12; i++) {
            sum += parseInt(cnpj[i]) * weights1[i];
        }
        let remainder = sum % 11;
        let digit1 = remainder < 2 ? 0 : 11 - remainder;
        
        if (digit1 !== parseInt(cnpj[12])) return false;
        
        // Calculate second digit
        const weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        sum = 0;
        for (let i = 0; i < 13; i++) {
            sum += parseInt(cnpj[i]) * weights2[i];
        }
        remainder = sum % 11;
        let digit2 = remainder < 2 ? 0 : 11 - remainder;
        
        return digit2 === parseInt(cnpj[13]);
    }

    // Initialize on page load
    function initDocumentField() {
        applyDocumentMask();
        addValidationFeedback();
    }

    // Initialize immediately
    initDocumentField();

    // Re-initialize on checkout update (for AJAX updates)
    $(document.body).on('updated_checkout', function() {
        setTimeout(initDocumentField, 100);
    });

    // Re-initialize if field appears dynamically
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                const documentField = document.getElementById('billing_document');
                if (documentField && !documentField.hasAttribute('data-superfrete-initialized')) {
                    documentField.setAttribute('data-superfrete-initialized', 'true');
                    initDocumentField();
                }
            }
        });
    });

    // Start observing
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});