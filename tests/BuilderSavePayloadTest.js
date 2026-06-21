// Placeholder so the JS payload that hits the wire is documented in
// tree. The real PHP test for the same payload is BuilderSavePayloadTest.php.
//
// Builder.js (modules/admin/src/forms/Builder.js) sends this exact body
// shape to POST /wp-json/neural-forms/v1/forms when the user saves a
// brand-new form for the first time:
//
//   {
//     name:        "Untitled form",   // user-editable
//     slug:        "",                // empty - server must derive from name
//     status:      "draft",
//     form_schema: { fields: [] },    // may contain rows + columns
//     settings:    {}
//   }
//
// The empty `slug` is what triggered the WP_REST_Request->__toString fatal
// before the FormsEndpoint::create_form / sanitize_callback fix.
