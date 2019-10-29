<script type="text/javascript">
  require(['NS8_Protect/js/postmate.min'], function(Postmate) {
    const handshake = new Postmate({
      container: document.getElementById('ns8-protect-wrapper'),
      url: '<?php echo $block->getNS8ClientUrl(); ?>',
      classListArray: ['ns8-protect-client-iframe']
    });

    const matchIframeHeightToContent = (iframe) => iframe.get('height')
      .then(height => iframe.frame.style.height = `${height}px`);

    const beginIframeHeightMatching = (iframe) => {
      iframe.call('setStyle', {
        selector: 'html',
        property: 'overflow',
        value: 'hidden'
      });

      iframe.call('setStyle', {
        selector: 'html',
        property: 'height',
        value: 'auto'
      });

      iframe.call('setStyle', {
        selector: 'body',
        property: 'overflow-y',
        value: 'hidden'
      });

      matchIframeHeightToContent(iframe);

      /*
       * Unfortunately, there is no "natural" way to detect the
       * height change of a document, so for now we resort to polling
       * 200 ms seems fast enough to feel automatic without
       * bogging down the browser.
       */
      window.setInterval(() => matchIframeHeightToContent(iframe), 200);
    };

    handshake.then(
      child => child.on('ns8-protect-client-connected', () => beginIframeHeightMatching(child))
    );
  });
</script>

<div id="ns8-protect-wrapper"></div>
