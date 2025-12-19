document.addEventListener("DOMContentLoaded", () => {
    const loginForm = document.getElementById("loginForm");
    const passwordField = document.getElementById("password");
    const forgotPassword = document.getElementById("forgotPassword");
    const showPasswordCheckbox = document.getElementById("showPassword");

    // ================================
    // Disable back button
    // ================================
    function disableBack() {
        window.history.pushState(null, "", window.location.href);
        window.onpopstate = function () {
            window.history.pushState(null, "", window.location.href);
        };
    }

    // ================================
    // Show/Hide password toggle
    // ================================
    if (showPasswordCheckbox) {
        showPasswordCheckbox.addEventListener("change", () => {
            passwordField.type = showPasswordCheckbox.checked ? "text" : "password";
        });
    }

    // ================================
    // Validate input fields
    // ================================
    function validateInputs(email, password) {
        if (!email || !password) {
            alert("All fields are required.");
            return false;
        }
        if (password.length < 6 || password.length > 20) {
            alert("Password must be between 6 and 20 characters.");
            return false;
        }
        return true;
    }

    // ================================
    // Handle form submit
    // ================================
    loginForm.addEventListener("submit", (event) => {
        event.preventDefault(); // prevent page reload

        const email = document.getElementById("mail").value.trim();
        const password = passwordField.value.trim();

        // Validate before sending
        if (!validateInputs(email, password)) return;

        // ================================
        // Send login request to PHP
        // ================================
        fetch("../php/log-in.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `mail=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === "success") {
                    // Successful login
                    alert(data.message);
                    window.location.href = "index.php"; // redirect to main system
                } else {
                    // Failed login
                    alert(data.message);

                    // Show forgot password link if wrong credentials
                    if (data.message.includes("Invalid email or password")) {
                        forgotPassword.style.display = "block";
                    }
                }
            })
            .catch(err => {
                console.error("Login error:", err);
                alert("Something went wrong. Please try again later.");
            });
    });

    disableBack();
});
