jQuery(function($){
    $('#ll-submit').on('click', function(e){
      e.preventDefault();
      const loc = $('#ll-location').val().trim();
      const ind = $('#ll-industry').val().trim();
      const $results = $('#ll-results');
      $results.html('<p>Searching for leads…</p>');
  
      $.post(LocalLeadAI.ajax_url, {
        action: 'locallead_ai_get_leads',
        location: loc,
        industry: ind
      }).done(function(res){
        if(res.success){
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
          res.data.forEach(function(lead){
            const nameCell = lead.website
              ? `<a href="${lead.website}" target="_blank">${lead.name}</a>`
              : lead.name;
  
            let contactLink = '';
            if(lead.website){
              try{
                const urlObj = new URL(lead.website);
                contactLink = urlObj.origin + '/contact';
              }catch(err){
                contactLink = '';
              }
            }
            const contactCell = contactLink
              ? `<a href="${contactLink}" target="_blank">Contact</a>`
              : '';
  
            // Email from lead data if available
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
          $results.html(html);
        } else {
          $results.html(`<p>${res.data}</p>`);
        }
      }).fail(function(){
        $results.html('<p>Unexpected error fetching leads.</p>');
      });
    });
  });
  