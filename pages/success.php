<?php

echo "<!DOCTYPE html>";
echo "<html lang=en>";
echo "<head>";
    echo "<meta charset=UTF-8>";
    echo "<title>Success!</title>";
echo "</head>";
echo "<body>";

    echo "<h1>Success! 🎉</h1>";
    echo "<p>Your form submission was received successfully.</p>";
    echo "<p>Thank you for submitting your information.</p>";

    echo "<p><a href=reader.php>Go back to the homepage</a></p>";

echo "</body>";
echo "</html>";
//
//
//<?php
//// Start the session
//
//
//// Check if the form was already submitted
//if (isset($_SESSION['form_submitted']) && $_SESSION['form_submitted'] == true) {
//    // If the form was already submitted, prevent re-processing
//    echo "The form has already been submitted.";
//    exit;  // Exit the script to prevent further processing
//}
//
//// Check if the form was submitted (assuming it's a POST request)
//if ($_SERVER['REQUEST_METHOD'] == 'POST') {
//    // Process the form data here
//    // For example, let's just show the form data
//    $name = $_POST['name'];
//    $email = $_POST['email'];
//    echo "Form submitted successfully!<br>";
//    echo "Name: $name<br>Email: $email<br>";
//
//    // Set a session variable to prevent double submission
//    $_SESSION['form_submitted'] = true;
//
//    // Optionally, you can redirect to another page after processing
//    // header("Location: success.php");
//    // exit();
//}
//

<dialog id="dialog">
    <form>
    <button type="submit" aria-label="close" formmethod="dialog" formnovalidate>X</button>
    <h2 id="dialogid">MLW Registration</h2>
    <p>All fields are required</p>
    <p>
        <label>Name:
           <input type="text" name="name" required />
       </label>
    </p>
    <p>
       <label>Warranty:
          <input type="number" min="0" max="10" step=”1” name="warranty" required />
        </label>
    </p>
    <p>
       <label>Power source:
           <select name="powersoure">
          <option>AC/DC</option>
          <option>Battery</option>
          <option>Solar</option>
           </select>
       </label>
    </p>
    <p>
       <button type="submit" formmethod="post">Submit</button>
    </p>
 <hr/>
    <p>Additional buttons</p>
      <button id="jsbutton">JS close</button>
      <button id="reset" type="reset">Reset</button>
    </p>
  </form>
</dialog>