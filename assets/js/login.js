/**
 * Client-Side Authentication Handler for PolyAuth System
 * Handles login submission via jQuery $.ajax to php/login.php REST API
 */

$(document).ready(function () {

  const $form = $('#loginForm');
  const $alertContainer = $('#alertContainer');
  const $btnLogin = $('#btnLogin');
  const $btnText = $('#btnText');
  const $btnSpinner = $('#btnSpinner');

  // Check if session token already exists in localStorage; if so, verify session
  const existingToken = localStorage.getItem('session_token');
  if (existingToken) {
    $.ajax({
      url: 'php/profile.php',
      type: 'GET',
      headers: {
        'Authorization': 'Bearer ' + existingToken
      },
      success: function (res) {
        if (res.success) {
          window.location.href = 'profile.html';
        }
      }
    });
  }

  /**
   * Display Custom Alert Banner
   */
  function showAlert(message, type = 'danger') {
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    const alertHtml = `
      <div class="custom-alert custom-alert-${type} fade show mb-4" role="alert">
        <i class="bi ${icon} fs-5"></i>
        <div>${message}</div>
      </div>
    `;
    $alertContainer.html(alertHtml).removeClass('d-none');
  }

  /**
   * Hide Alert Banner
   */
  function hideAlert() {
    $alertContainer.addClass('d-none').empty();
  }

  /**
   * Set Loading State on Button
   */
  function setLoading(loading) {
    if (loading) {
      $btnLogin.prop('disabled', true);
      $btnText.text(' Authenticating...');
      $btnSpinner.removeClass('d-none');
    } else {
      $btnLogin.prop('disabled', false);
      $btnText.html('<i class="bi bi-box-arrow-in-right me-2"></i> Sign In to Dashboard');
      $btnSpinner.addClass('d-none');
    }
  }

  /**
   * Handle Login Submission via jQuery AJAX
   */
  $form.on('submit', function (e) {
    e.preventDefault();
    hideAlert();

    const email = $('#email').val().trim();
    const password = $('#password').val();

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showAlert('Please provide a valid email address.');
      return;
    }

    if (!password) {
      showAlert('Password field cannot be empty.');
      return;
    }

    setLoading(true);

    const payload = {
      email: email,
      password: password
    };

    // jQuery AJAX POST Request
    $.ajax({
      url: 'php/login.php',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload),
      dataType: 'json',
      success: function (response) {
        setLoading(false);
        if (response.success && response.session_token) {
          // Store token in localStorage for client bearer header support
          localStorage.setItem('session_token', response.session_token);
          showAlert('Authentication successful! Redirecting to dashboard...', 'success');
          setTimeout(function () {
            window.location.href = 'profile.html';
          }, 1000);
        } else {
          showAlert(response.message || 'Login failed. Invalid credentials.');
        }
      },
      error: function (xhr) {
        setLoading(false);
        let errorMsg = 'Invalid email or password.';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMsg = xhr.responseJSON.message;
        }
        showAlert(errorMsg);
      }
    });

  });

});
