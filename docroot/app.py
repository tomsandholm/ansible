import csv
import os
from flask import Flask, render_template_string, request

app = Flask(__name__)

# HTML template embedded directly for simplicity
HTML_TEMPLATE = """
<!header>
<html>
<head>
    <title>Key Registration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; background-color: #f4f4f9; }
        .container { max-width: 400px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        h2 { color: #333; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        input, textarea { width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { height: 100px; resize: vertical; }
        button { margin-top: 20px; background-color: #007BFF; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; width: 100%; }
        button:hover { background-color: #0056b3; }
        .message { margin-top: 20px; padding: 10px; border-radius: 4px; background-color: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Submit Public Key</h2>
        {% if message %}
            <div class="message">{{ message }}</div>
        {% endif %}
        <form method="POST">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required autocomplete="off">
            
            <label for="public_key">Public Key:</label>
            <textarea id="public_key" name="public_key" required></textarea>
            
            <button type="submit">Save Key</button>
        </form>
    </div>
</body>
</html>
"""


@app.route("/", methods=["GET", "POST"])
def index():
    message = None
    if request.method == "POST":
        # Extract form data
        username = request.form.get("username", "").strip()
        public_key = request.form.get("public_key", "").strip()

        if username and public_key:
            # Sanitize username to prevent directory traversal attacks
            safe_username = "".join(
                c for c in username if c.isalnum() or c in ("-", "_")
            )
            filename = f"{safe_username}.csv"

            # Check if file exists to determine if we need a header
            file_exists = os.path.isfile(filename)

            # Write data to the CSV file named after the username
            with open(filename, mode="a", newline="", encoding="utf-8") as file:
                writer = csv.writer(file)
                if not file_exists:
                    writer.writerow(["Username", "Public Key"])  # Header row
                writer.writerow([username, public_key])

            message = f"Success! Data saved to {filename}"

    return render_template_string(HTML_TEMPLATE, message=message)


if __name__ == "__main__":
    app.run(debug=True, port=5000)

