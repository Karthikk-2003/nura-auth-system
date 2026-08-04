/**
 * Client-Side Registration Handler for PolyAuth System
 * Submits form data asynchronously via jQuery $.ajax to php/register.php REST API
 */

$(document).ready(function () {

  const $form = $('#registerForm');
  const $alertContainer = $('#alertContainer');
  const $btnRegister = $('#btnRegister');
  const $btnText = $('#btnText');
  const $btnSpinner = $('#btnSpinner');

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
    $('html, body').animate({ scrollTop: $alertContainer.offset().top - 100 }, 300);
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
      $btnRegister.prop('disabled', true);
      $btnText.text(' Processing Registration...');
      $btnSpinner.removeClass('d-none');
    } else {
      $btnRegister.prop('disabled', false);
      $btnText.html('<i class="bi bi-person-plus-fill me-2"></i> Complete Registration');
      $btnSpinner.addClass('d-none');
    }
  }

  /**
   * Handle Form Submission via jQuery AJAX
   */
  $form.on('submit', function (e) {
    e.preventDefault();
    hideAlert();

    const username = $('#username').val().trim();
    const email = $('#email').val().trim();
    const password = $('#password').val();
    const name = $('#name').val().trim();
    const age = $('#age').val().trim();
    const bio = $('#bio').val().trim();
    const rawInterests = $('#interests').val().trim();

    // Client-side Input Validation
    if (!username || !/^[a-zA-Z0-9_]{3,30}$/.test(username)) {
      showAlert('Username must be 3-30 characters long and contain only letters, numbers, and underscores.');
      return;
    }

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showAlert('Please enter a valid email address.');
      return;
    }

    if (!password || password.length < 6) {
      showAlert('Password must be at least 6 characters long.');
      return;
    }

    if (!name) {
      showAlert('Please enter your full name.');
      return;
    }

    if (!age || isNaN(age) || parseInt(age, 10) < 1 || parseInt(age, 10) > 120) {
      showAlert('Please enter a valid age between 1 and 120.');
      return;
    }

    // Convert comma-separated interests to array
    const interests = rawInterests ? rawInterests.split(',').map(item => item.trim()).filter(Boolean) : [];

    const payload = {
      username: username,
      email: email,
      password: password,
      name: name,
      age: parseInt(age, 10),
      bio: bio,
      interests: interests
    };

    setLoading(true);

    // jQuery AJAX POST Request
    $.ajax({
      url: 'php/register.php',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload),
      dataType: 'json',
      success: function (response) {
        setLoading(false);
        if (response.success) {
          showAlert(response.message || 'Registration successful! Redirecting to login page...', 'success');
          $form[0].reset();
          setTimeout(function () {
            window.location.href = 'login.html';
          }, 1800);
        } else {
          showAlert(response.message || 'Registration failed. Please check your details.');
        }
      },
      error: function (xhr) {
        setLoading(false);
        let errorMsg = 'An error occurred during registration. Please try again.';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMsg = xhr.responseJSON.message;
        } else if (xhr.responseText) {
          try {
            const parsed = JSON.parse(xhr.responseText);
            if (parsed.message) errorMsg = parsed.message;
          } catch (e) {
            // Keep default message
          }
        }
        showAlert(errorMsg);
      }
    });

  });

});
