jQuery(function($){
    let lastLeads = [];
  
    // Hide upgrade section initially
    function initForm() {
      $('.ll-upgrade-section').hide();
    }
  
    // Render table of leads
    function renderTable(leads) {
      lastLeads = leads;
      let html = `
        <table class="locallead-table">
          <thead>
            <tr>
              <th>Name (Click - See Website)</th>
              <th>Address</th>
              <th>Contact</th>
              <th>Email</th>
              <th>Phone</th>
            </tr>
          </thead>
          <tbody>
      `;
      leads.forEach(function(lead){
        const nameCell = lead.website
          ? `<a href="${lead.website}" target="_blank">${lead.name}</a>`
          : lead.name;
  
        let contactLink = '';
        if(lead.website){
          try{
            const urlObj = new URL(lead.website);
            contactLink = urlObj.origin + '/contact';
          }catch(err){ }
        }
        const contactCell = contactLink
          ? `<a href="${contactLink}" target="_blank">Contact</a>`
          : '';
  
        const emailCell = lead.email
          ? `<a href="mailto:${lead.email}">${lead.email}</a>`
          : '';
  
        html += `
          <tr>
            <td>${nameCell}</td>
            <td>${lead.address}</td>
            <td>${contactCell}</td>
            <td>${emailCell}</td>
            <td>${lead.phone || ''}</td>
          </tr>
        `;
      });
      html += `
          </tbody>
        </table>
      `;
      $('#ll-results').html(html);
      // Show upgrade section if allowed
      if(LocalLeadAI.can_email_all) {
        $('.ll-upgrade-section').show();
        $('#ll-export-btn').show();
      }
    }
  
    // Download CSV
    function downloadCSV(leads) {
      let csv = 'Name,Address,Contact Page,Email,Phone\n';
      leads.forEach(l => {
        csv += `"${l.name}","${l.address}","${l.contact}","${l.email}","${l.phone}"\n`;
      });
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'localleads.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    }
  
    // Email all results
    function emailResults(location, industry) {
      $.post(LocalLeadAI.ajax_url, {
        action: 'locallead_ai_email_results',
        location: location,
        industry: industry
      }).done(function(res){
        alert(res.success ? res.data : 'Error: ' + res.data);
      }).fail(function(){
        alert('Unexpected error sending leads.');
      });
    }
  
    // Handle form submit
    $('#ll-submit').on('click', function(e){
      e.preventDefault();
      const loc = $('#ll-location').val().trim();
      const ind = $('#ll-industry').val().trim();
      $('#ll-results').html('<p>Searching for leads…</p>');
      initForm();
      $.post(LocalLeadAI.ajax_url, {
        action: 'locallead_ai_get_leads',
        location: loc,
        industry: ind
      }).done(function(res){
        if(res.success){
          renderTable(res.data);
        } else {
          $('#ll-results').html(`<p>${res.data}</p>`);
        }
      }).fail(function(){
        $('#ll-results').html('<p>Unexpected error fetching leads.</p>');
      });
    });
  
    // Toggle email button
    $('#ll-email-all').on('change', function(){
      $('#ll-email-btn').toggle(this.checked);
    });
  
    // Export CSV click
    $('#ll-export-btn').on('click', function(){
      downloadCSV(lastLeads);
    });
  
    // Email leads click
    $('#ll-email-btn').on('click', function(){
      const loc = $('#ll-location').val().trim();
      const ind = $('#ll-industry').val().trim();
      emailResults(loc, ind);
    });
  
    // Initialize on load
    initForm();
  });
  