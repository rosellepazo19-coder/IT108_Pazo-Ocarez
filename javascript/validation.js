document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector("form");

    // Fields
    const passwordField = document.getElementById("password");
    const confirmPasswordField = document.getElementById("confirmPassword");
    const birthdayField = document.getElementById("Birthday");
    const ageField = document.getElementById("Age");
    const idField = document.getElementById("idnum");
    const emailField = document.getElementById("mail");
    const suffixField = document.getElementById("Suffix");
    const zipField = document.getElementById("ZipCode");
    const strengthStatus = document.getElementById("password-strength-status");
    const usernameField = document.getElementById("username");
    const middleNameField = document.getElementById("Mname");
    const streetField = document.getElementById("Street");
    const barangayField = document.getElementById("Barangay");
    const cityField = document.getElementById("City");
    const provinceField = document.getElementById("Province");
    const countryField = document.getElementById("Country");

    const fname = document.getElementById("Fname");
    const mname = document.getElementById("Mname");
    const lname = document.getElementById("Lname");

    const secQuestions = [
        { q: document.getElementById("secQ1"), a: document.getElementById("secA1") },
        { q: document.getElementById("secQ2"), a: document.getElementById("secA2") },
        { q: document.getElementById("secQ3"), a: document.getElementById("secA3") }
    ].filter(q => q.q && q.a);

    let idExists = false;
    let emailExists = false;

    // ===============================
    // Helper functions
    // ===============================
    const showError = (field, message) => {
        showFieldError(field, message);
        field.focus();
        return false;
    };

    const showFieldError = (field, message) => {
        field.style.borderColor = '#e74c3c';
        field.style.backgroundColor = '#fdf2f2';
        const existingError = field.parentNode.querySelector('.field-error');
        if (existingError) existingError.remove();
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    };

    const clearFieldError = (field) => {
        field.style.borderColor = '';
        field.style.backgroundColor = '';
        const existingError = field.parentNode.querySelector('.field-error');
        if (existingError) existingError.remove();
    };

    // Auto-format names
    function autoFormatName(input) {
        input.addEventListener("input", function () {
            if (this.value.length > 0) {
                const lower = this.value.toLowerCase();
                this.value = lower.replace(/(^[a-z])|([\s-][a-z])/g, (m) => m.toUpperCase());
            }
        });
    }
    [fname, mname, lname, usernameField, streetField, barangayField, cityField, provinceField, countryField, middleNameField]
        .forEach(field => autoFormatName(field));

    const isValidName = (value) => {
        if (!value) return false;
        if (/[^a-zA-Z\-\s]/.test(value)) return false;
        if (/\s{2,}/.test(value)) return false;
        if (/([a-zA-Z])\1{2,}/.test(value)) return false; // only for letters
        return true;
    };

    const isValidSuffix = (value) => true;
    const isValidEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    const isValidID = (value) => /^[0-9]{4}-[0-9]{4}$/.test(value);
    const isValidZip = (value) => /^[0-9]{4,5}$/.test(value);

    const checkPasswordStrength = (password) => {
        const weak = /^(?=.*[a-zA-Z])(?=.*\d)[A-Za-z\d]{6,}$/;
        const medium = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d]{8,}$/;
        const strong = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]{8,}$/;

        if (!password) {
            strengthStatus.textContent = "";
            strengthStatus.style.color = "";
            strengthStatus.style.background = "";
            strengthStatus.style.borderColor = "";
            return;
        }

        if (strong.test(password)) {
            strengthStatus.textContent = "Strong";
            strengthStatus.style.color = "#fff";
            strengthStatus.style.background = "linear-gradient(135deg, #28a745 0%, #20c997 100%)";
            strengthStatus.style.borderColor = "#28a745";
        } else if (medium.test(password)) {
            strengthStatus.textContent = "⚠ Medium";
            strengthStatus.style.color = "#fff";
            strengthStatus.style.background = "linear-gradient(135deg, #ffc107 0%, #fd7e14 100%)";
            strengthStatus.style.borderColor = "#ffc107";
        } else if (weak.test(password)) {
            strengthStatus.textContent = "⚠ Weak";
            strengthStatus.style.color = "#fff";
            strengthStatus.style.background = "linear-gradient(135deg, #dc3545 0%, #e83e8c 100%)";
            strengthStatus.style.borderColor = "#dc3545";
        } else {
            strengthStatus.textContent = "✗ Weak";
            strengthStatus.style.color = "#fff";
            strengthStatus.style.background = "linear-gradient(135deg, #6c757d 0%, #495057 100%)";
            strengthStatus.style.borderColor = "#6c757d";
        }
    };

    const calculateAge = (birthdate) => {
        const today = new Date();
        const birth = new Date(birthdate);
        let age = today.getFullYear() - birth.getFullYear();
        const monthDiff = today.getMonth() - birth.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) age--;
        return age;
    };

    // ===============================
    // Real-time checks
    // ===============================
    if (passwordField) {
        passwordField.addEventListener("input", () => checkPasswordStrength(passwordField.value));
    }

    if (birthdayField && ageField) {
        function updateAge() {
            if (birthdayField.value) {
                const calculatedAge = calculateAge(birthdayField.value);
                if (calculatedAge >= 0) {
                    ageField.value = calculatedAge;
                } else {
                    ageField.value = '';
                }
            } else {
                ageField.value = '';
            }
        }
        
        birthdayField.addEventListener("change", updateAge);
        birthdayField.addEventListener("input", updateAge);
        
        // Calculate if birthday already has a value
        if (birthdayField.value) {
            updateAge();
        }
    }

    function collapseSpaces(el) {
        el.value = el.value.replace(/\s{2,}/g, " ");
    }

    function hasTripleRepeatLettersCI(value) {
        const str = value || '';
        for (let i = 0; i + 2 < str.length; i++) {
            const a = str[i], b = str[i + 1], c = str[i + 2];
            if (/[A-Za-z]/.test(a) && /[A-Za-z]/.test(b) && /[A-Za-z]/.test(c)) {
                if (a.toLowerCase() === b.toLowerCase() && b.toLowerCase() === c.toLowerCase()) return true;
            }
        }
        return false;
    }

    function isAllCapsLetters(value) {
        const letters = (value || '').replace(/[^A-Za-z]/g, '');
        return letters && letters === letters.toUpperCase();
    }

    document.querySelectorAll("input").forEach((input) => {
        input.addEventListener("input", function () {
            collapseSpaces(this);
        });
        input.addEventListener("blur", function () {
            collapseSpaces(this);
            const el = this;
            const idLower = (el.id || '').toLowerCase();
            const nameLower = (el.name || '').toLowerCase();
            const isEmail = el.type === 'email' || idLower.includes('mail') || nameLower.includes('mail');
            const isIdNum = idLower.includes('idnum') || nameLower.includes('idnum');
            const isPassword = el.type === 'password';
            const isUsername = idLower.includes('username');

            if (hasTripleRepeatLettersCI(el.value)) {
                showFieldError(el, 'Input cannot contain three identical consecutive letters.');
                return;
            }

            if (!isPassword && isAllCapsLetters(el.value)) {
                showFieldError(el, 'Please avoid using ALL capital letters.');
                return;
            }

            // Disallow special characters except hyphen
            if (!isEmail && !isIdNum && !isPassword && !isUsername) {
                if (/[^A-Za-z0-9\-\s]/.test(el.value)) {
                    showFieldError(el, 'Only letters, numbers, spaces, and hyphen (-) are allowed.');
                    return;
                }
            }

            // Username-specific rule (allow letters, digits, hyphen only)
            if (isUsername) {
                if (!/^[A-Za-z0-9\-]+$/.test(el.value)) {
                    showFieldError(el, 'Username can only contain letters, numbers, and hyphen (-).');
                    return;
                }
                if (/([a-zA-Z0-9])\1{2,}/.test(el.value)) {
                    showFieldError(el, 'Username cannot have three identical consecutive characters.');
                    return;
                }
            }

            if (isEmail) {
                if (/([A-Za-z0-9])\1{2,}/.test(el.value)) {
                    showFieldError(el, 'Email cannot contain 3 identical consecutive characters.');
                    return;
                }
            }
        });
    });

    // ===============================
    // AJAX duplicate checks
    // ===============================
    idField.addEventListener("blur", async () => {
        if (idField.value) {
            try {
                const response = await fetch(`check_username.php?idnum=${encodeURIComponent(idField.value)}`);
                const data = await response.json();
                idExists = data.exists;
                if (idExists) {
                    showFieldError(idField, "This ID number is already registered!");
                    idField.setAttribute('data-exists', 'true');
                } else {
                    clearFieldError(idField);
                    idField.removeAttribute('data-exists');
                }
            } catch (error) {
                console.error("Error checking ID number:", error);
            }
        }
    });

    emailField.addEventListener("blur", async () => {
        if (emailField.value) {
            try {
                const response = await fetch(`check_email.php?mail=${encodeURIComponent(emailField.value)}`);
                const data = await response.json();
                emailExists = data.exists;
                if (emailExists) {
                    showFieldError(emailField, "This email is already registered!");
                    emailField.setAttribute('data-exists', 'true');
                } else {
                    clearFieldError(emailField);
                    emailField.removeAttribute('data-exists');
                }
            } catch (error) {
                console.error("Error checking email:", error);
            }
        }
    });

    // ===============================
    // Final form validation
    // ===============================
    window.validateForm = () => {
        if (!isValidName(fname.value)) return showError(fname, "Invalid first name format.");
        if (mname.value && !isValidName(mname.value)) return showError(mname, "Invalid middle name format.");
        if (!isValidName(lname.value)) return showError(lname, "Invalid last name format.");
        if (!isValidSuffix(suffixField.value)) return showError(suffixField, "Please select a valid suffix.");

        if (!isValidEmail(emailField.value)) return showError(emailField, "Invalid email address.");
        const emailLetters = emailField.value.replace(/[^A-Za-z]/g, '');
        if (emailLetters && emailLetters === emailLetters.toUpperCase()) return showError(emailField, "Email cannot be all capital letters.");
        if (/([A-Za-z0-9])\1{2,}/.test(emailField.value)) return showError(emailField, "Email cannot contain 3 identical consecutive characters.");
        if (!isValidID(idField.value)) return showError(idField, "ID must follow YYYY-#### format (e.g., 2025-0001).");
        if (!isValidZip(zipField.value)) return showError(zipField, "Zip code must be 4-5 digits.");

        const age = parseInt(ageField.value);
        if (isNaN(age) || age < 18) return showError(ageField, "You must be at least 18 years old.");

        if (passwordField.value !== confirmPasswordField.value) return showError(confirmPasswordField, "Passwords do not match.");

        if (secQuestions.length) {
            const selectedQs = new Set();
            for (let { q, a } of secQuestions) {
                if (!q.value) return showError(q, "Please select a security question.");
                if (!a.value || a.value.trim().length < 3) return showError(a, "Security answers must be at least 3 characters.");
                const lettersOnly = a.value.replace(/[^A-Za-z]/g, '');
                if (lettersOnly && lettersOnly === lettersOnly.toUpperCase()) return showError(a, "Security answer cannot be all capital letters.");
                if (selectedQs.has(q.value)) return showError(q, "Each security question must be unique.");
                selectedQs.add(q.value);
            }
        }

        return true;
    };
});
